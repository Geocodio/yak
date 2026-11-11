package teleport

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/geocodio/yak/cli/internal/api"
	"github.com/geocodio/yak/cli/internal/apply"
	"github.com/geocodio/yak/cli/internal/clash"
	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/detect"
	"github.com/geocodio/yak/cli/internal/gitx"
	"github.com/geocodio/yak/cli/internal/session"
	"github.com/geocodio/yak/cli/internal/ui"
)

// DownOptions answer the prompts ahead of time for non-interactive use. RunningTurn is wait or stop, Visibility
// is keep or private, Resolution is stash or worktree. FromLink is set when a yak:// link started the command.
type DownOptions struct {
	RunningTurn string
	Visibility  string
	Resolution  string
	FromLink    bool
}

// DownInput names the thread and the checkout to bring it to.
type DownInput struct {
	ThreadID int
	Cwd      string
	Options  DownOptions
}

// DownResult says where the thread now lives. The command resumes Claude in ResumeDir.
type DownResult struct {
	ThreadID  int
	SessionID string
	ResumeDir string
	Applied   []AppliedRepo
}

// serverStopped marks a failure after which Yak already ended the teleport, so there is nothing to cancel.
type serverStopped struct{ *UserError }

var progressLines = map[string]string{
	"wait_turn": "Waited for the running turn to finish", "stop_turn": "Stopped the running turn",
	"commit_wip": "Yak committed its work", "push": "Yak pushed the branch", "sync_session": "Yak synced the session",
}

// Down brings a thread from Yak to this checkout. Nothing is touched before the clash check and the change
// detection pass, and ownership moves to this computer only in the last request.
func Down(ctx context.Context, d Deps, in DownInput) (result DownResult, err error) {
	repo := gitx.Repo{Dir: in.Cwd}
	top, topErr := repo.Toplevel()
	if topErr != nil {
		return result, userErrorf(topErr, "This folder is not a git checkout. Go to a checkout of the thread's repo first.")
	}
	repo.Dir = top

	var found api.ThreadResponse
	if err := d.API.Do(ctx, "GET", fmt.Sprintf("/threads/%d", in.ThreadID), nil, &found); err != nil {
		return result, friendly(err)
	}
	thread := found.Thread
	item, onYak, err := resolveCheckout(ctx, d, repo)
	if err != nil {
		return result, err
	}
	if !onYak || *item.Repo != thread.Repo {
		hint := ""
		if known := d.Config.Checkouts[thread.Repo]; known != "" {
			hint = fmt.Sprintf(" Last time you used %s.", known)
		}
		return result, &Refusal{Kind: "wrong_repo", Detail: fmt.Sprintf("Thread %d is in %s, but this checkout is not. Run yak teleport %d from a checkout of %s.%s", thread.ID, thread.Repo, thread.ID, thread.Repo, hint)}
	}
	if in.Options.FromLink {
		d.say("Teleport thread %d \"%s\" (%s) to this computer?", thread.ID, thread.Title, thread.Repo)
		yes, err := d.Prompter.Confirm("Continue?", false)
		if err != nil {
			return result, err
		}
		if !yes {
			return result, &Refusal{Kind: "declined", Detail: "Cancelled. Nothing changed."}
		}
	}

	started, err := beginDown(ctx, d, thread, in.Options, top, *item.Repo)
	if err != nil {
		return result, err
	}
	done := false
	defer func() {
		var stopped serverStopped
		if err == nil || done || errors.As(err, &stopped) || api.IsCode(err, "teleport_failed") || api.IsCode(err, "teleport_expired") || api.IsCode(err, "device_revoked") {
			return
		}
		reason := "error"
		var refusal *Refusal
		switch {
		case isCancelled(err):
			reason = "user_cancelled"
		case errors.As(err, &refusal):
			reason = map[string]string{"clash": "clash", "dirty_tree": "dirty_tree"}[refusal.Kind]
			if reason == "" {
				reason = "user_cancelled"
			}
		}
		d.cancel(ctx, started.TeleportID, reason, err.Error())
	}()

	manifest, err := pollReady(ctx, d, started)
	if err != nil {
		return result, err
	}
	primary, ok := primaryOf(manifest)
	if !ok {
		return result, userErrorf(nil, "Yak sent a thread without a primary repo.")
	}
	if primary.WIPCommitMade {
		d.say("Yak committed its work and pushed %s", primary.Branch)
	}

	tip, branch := primary.HeadSHA, primary.Branch
	remote, err := remoteFor(repo, item.Remote)
	if err != nil {
		return result, err
	}
	if _, err := repo.Run("fetch", "--quiet", remote, "refs/heads/"+branch); err != nil {
		return result, userErrorf(err, "Could not fetch %s from GitHub: %v", branch, err)
	}
	if _, err := repo.Run("cat-file", "-e", tip+"^{commit}"); err != nil {
		return result, userErrorf(err, "GitHub does not have the commit Yak pushed for %s.", branch)
	}

	report, err := clash.Check(repo, tip)
	if err != nil {
		return result, err
	}
	if !report.Empty() {
		printClash(d, report, thread.ID)
		return result, &Refusal{Kind: "clash", Detail: "this computer cannot hold some of the thread's files", Paths: clashPaths(report)}
	}

	mode, err := chooseApplyMode(d, repo, top, in.Options, manifest, branch, tip, thread.ID)
	if err != nil {
		return result, err
	}
	resumeDir := top
	if mode == "worktree" {
		_, resumeDir = downFolders(top, manifest.Thread.Slug)
	}
	printDownPlan(d, manifest, mode, top)

	projectDir := filepath.Join(session.ClaudeConfigDir(d.Env, d.Home), "projects", session.ProjectFolderName(resumeDir))
	if err := os.MkdirAll(projectDir, 0o755); err != nil {
		return result, err
	}
	written, backup, err := downloadSession(ctx, d, thread.ID, manifest, projectDir)
	if err != nil {
		return result, userErrorf(err, "Could not download the session, so nothing was applied: %v", err)
	}
	if backup != "" {
		d.say("Your copy of this session had changed since the last teleport. It is saved as %s.", backup)
	}

	applied, resumeDir, err := applyRepos(ctx, d, repo, top, mode, manifest, primary, resumeDir)
	if err != nil {
		return result, err
	}

	body := api.CompleteDownRequest{Applied: applied, Downloaded: written}
	if backup != "" {
		body.LocalSessionBackup = filepath.Base(backup)
	}
	var completed api.CompleteDownResponse
	if err := d.API.Do(ctx, "POST", "/teleports/"+started.TeleportID+"/complete", body, &completed); err != nil {
		return result, friendly(err)
	}
	done = true

	sid := manifest.Session.SessionID
	marker := config.Marker{
		ThreadID: thread.ID, TeleportID: started.TeleportID, Owner: "device", ThreadURL: thread.URL,
		StackMessagesURL: completed.Marker.StackMessagesURL, BaseURL: d.Config.BaseURL, WrittenAt: d.Now(),
	}
	if err := config.WriteMarker(d.ConfigDir, sid, marker); err != nil {
		d.say("! Could not record that this computer owns the thread: %v", err)
	}
	writeNote(d, manifest, applied, resumeDir, projectDir, tip)
	remember(d, thread.Repo, top)

	d.say("Thread %d is now on this computer. Yak will not run turns on it until you teleport it back up.", thread.ID)
	d.say("Turns you run here use your local Claude login, not the %s account.", manifest.Thread.ClaudeAccount.Name)
	return DownResult{ThreadID: thread.ID, SessionID: sid, ResumeDir: resumeDir, Applied: applied}, nil
}

func beginDown(ctx context.Context, d Deps, thread api.Thread, opts DownOptions, top, slug string) (api.BeginDownResponse, error) {
	goos, arch := osArch()
	req := api.BeginDownRequest{
		Direction: "down", ThreadID: thread.ID, LocalPath: top, OS: goos, Arch: arch,
		Repos:       []api.BeginRepo{{Repo: slug, Role: "primary", LocalPath: top}},
		RunningTurn: opts.RunningTurn, Visibility: opts.Visibility,
	}
	var started api.BeginDownResponse
	for attempt := 0; attempt < 4; attempt++ {
		err := d.API.Do(ctx, "POST", "/teleports", req, &started)
		switch {
		case err == nil:
			return started, nil
		case api.IsCode(err, "turn_running") && req.RunningTurn == "":
			choice, err := d.Prompter.Choose("A turn is running on Yak for this thread.", []ui.Option{
				{Label: "Wait for it to finish", Hint: "default"}, {Label: "Stop it now", Hint: "the running turn is cut off"}, {Label: "Cancel"},
			}, 0)
			if err != nil {
				return started, err
			}
			switch choice {
			case 0:
				req.RunningTurn = "wait"
			case 1:
				yes, err := d.Prompter.Confirm("Stop the running turn now? Work it has not saved is lost.", false)
				if err != nil {
					return started, err
				}
				if !yes {
					return started, &Refusal{Kind: "declined", Detail: "Cancelled. Nothing changed."}
				}
				req.RunningTurn = "stop"
			default:
				return started, &Refusal{Kind: "declined", Detail: "Cancelled. Nothing changed."}
			}
		case api.IsCode(err, "visibility_choice_required") && req.Visibility == "":
			d.say("Local turns add what the local agent reads to the transcript, so the team can see it.")
			choice, err := d.Prompter.Choose("Keep this thread visible to the team, or make it private?", []ui.Option{
				{Label: "Keep it visible to the team", Hint: "default"}, {Label: "Make it private"},
			}, 0)
			if err != nil {
				return started, err
			}
			req.Visibility = []string{"keep", "private"}[choice]
		default:
			return started, friendly(err)
		}
	}
	return started, userErrorf(nil, "Yak kept asking questions. Run yak teleport again.")
}

func primaryOf(m api.DownManifest) (api.ManifestRepo, bool) {
	for _, r := range m.Repos {
		if r.Role == "primary" {
			return r, true
		}
	}
	return api.ManifestRepo{}, false
}

func pollReady(ctx context.Context, d Deps, started api.BeginDownResponse) (api.DownManifest, error) {
	shown := map[string]bool{}
	state := ""
	wait := max(started.Teleport.PollAfterSeconds, 1)
	for polls := 0; polls <= maxPolls; polls++ {
		if err := ctx.Err(); err != nil {
			return api.DownManifest{}, err
		}
		var raw json.RawMessage
		if err := d.API.Do(ctx, "GET", "/teleports/"+started.TeleportID, nil, &raw); err != nil {
			return api.DownManifest{}, friendly(err)
		}
		var probe struct {
			State    string        `json:"state"`
			Teleport *api.Teleport `json:"teleport"`
		}
		_ = json.Unmarshal(raw, &probe)
		tp := api.Teleport{State: probe.State}
		var manifest api.DownManifest
		if probe.Teleport != nil {
			if err := json.Unmarshal(raw, &manifest); err != nil {
				return manifest, err
			}
			tp = manifest.Teleport
		} else if err := json.Unmarshal(raw, &tp); err != nil {
			return manifest, err
		}
		for _, p := range tp.Progress {
			if line, ok := progressLines[p.Step]; ok && p.Status == "done" && !shown[p.Step] {
				shown[p.Step] = true
				d.say("%s", line)
			}
		}
		if tp.State != state {
			state = tp.State
			if state == "waiting_for_turn" {
				d.say("Waiting for the running turn to finish (ctrl-c cancels)...")
			} else if state == "preparing" {
				d.say("Yak is getting the thread ready...")
			}
		}
		switch tp.State {
		case "ready":
			return manifest, nil
		case "failed":
			msg := "Yak could not prepare the thread"
			if tp.Failure != nil {
				msg = tp.Failure.Message
			}
			return manifest, serverStopped{userErrorf(nil, "%s. Yak still has the thread.", strings.TrimRight(msg, "."))}
		case "cancelled", "expired":
			return manifest, serverStopped{userErrorf(nil, "The teleport %s. Yak still has the thread.", tp.State)}
		}
		if tp.PollAfterSeconds > 0 {
			wait = tp.PollAfterSeconds
		}
		d.Sleep(time.Duration(wait) * time.Second)
	}
	return api.DownManifest{}, userErrorf(nil, "Yak took too long to prepare the thread. Run yak teleport again.")
}

var clashTitles = map[string]string{
	"case_clash": "Names that differ only by case", "nfc_clash": "Names that differ only by Unicode form",
	"symlink_absolute": "A symlink with an absolute target", "symlink_escapes": "A symlink that points outside the repo",
	"symlink_unsupported": "A symlink this computer cannot create", "dot_git": "A path with a .git folder name",
	"submodule": "A submodule",
}

func clashPaths(r clash.Report) []string {
	var paths []string
	for _, v := range r.Violations {
		paths = append(paths, v.Paths...)
	}
	return paths
}

func printClash(d Deps, r clash.Report, threadID int) {
	ui.Fail(d.Out, "This computer cannot hold some of the thread's files.")
	for _, v := range r.Violations {
		title := clashTitles[v.Kind]
		if title == "" {
			title = v.Kind
		}
		if v.Detail != "" {
			title += " (" + v.Detail + ")"
		}
		d.say("\n  %s", title)
		for _, p := range v.Paths {
			d.say("    %s", p)
		}
	}
	d.say("\n  Nothing was changed here. Yak still has the thread.")
	d.say("\nTo fix it, ask Claude on Yak to rename or remove those paths, then run yak teleport %d again.", threadID)
}

// chooseApplyMode returns ff, stash or worktree after the change detection pass and the prompt.
func chooseApplyMode(d Deps, repo gitx.Repo, top string, opts DownOptions, m api.DownManifest, branch, tip string, threadID int) (string, error) {
	st, err := detect.Read(repo)
	if err != nil {
		return "", err
	}
	var base *detect.Baseline
	if m.LastUp != nil {
		base = &detect.Baseline{Branch: m.LastUp.Branch, Head: m.LastUp.HeadSHA}
	}
	diffs := detect.Differences(st, base)
	for _, diff := range diffs {
		if diff.Kind == "operation_in_progress" {
			return "", &Refusal{Kind: "dirty_tree", Detail: "a " + st.Operation + " is in progress here, finish or abort it first"}
		}
	}
	if len(diffs) == 0 {
		return "ff", nil
	}
	d.say("! This checkout is not ready for the thread:")
	for _, diff := range diffs {
		line := "  " + strings.ReplaceAll(diff.Kind, "_", " ")
		if diff.Path != "" {
			line += ": " + diff.Path
		}
		d.say("%s (%s)", line, diff.Detail)
	}
	stashOK, err := apply.StashAllowed(repo, branch, tip)
	if err != nil {
		return "", err
	}
	stashOK = stashOK && !st.Detached
	switch opts.Resolution {
	case "worktree":
		return "worktree", nil
	case "stash":
		if !stashOK {
			return "", &Refusal{Kind: "dirty_tree", Detail: "stash cannot be used here, the local branch has commits the thread does not have"}
		}
		return "stash", nil
	}
	options := []ui.Option{}
	modes := []string{}
	if stashOK {
		options = append(options, ui.Option{Label: "Stash them, then apply the thread", Hint: "git stash keeps your changes safe"})
		modes = append(modes, "stash")
	}
	_, worktreeDir := downFolders(top, m.Thread.Slug)
	options = append(options, ui.Option{Label: "Apply into a new worktree", Hint: worktreeDir + ", this checkout stays as it is"})
	modes = append(modes, "worktree")
	options = append(options, ui.Option{Label: "Cancel", Hint: "default"})
	modes = append(modes, "cancel")
	choice, err := d.Prompter.Choose("What should happen to your changes?", options, len(options)-1)
	if err != nil {
		return "", err
	}
	if modes[choice] == "cancel" {
		d.say("Cancelled. Nothing changed here. Yak still has thread %d.", threadID)
		return "", &Refusal{Kind: "dirty_tree", Detail: "this checkout has changes the thread does not know about"}
	}
	return modes[choice], nil
}

func downloadSession(ctx context.Context, d Deps, threadID int, m api.DownManifest, projectDir string) ([]api.DownloadedFile, string, error) {
	store := &session.Store{Client: d.API}
	var written []api.DownloadedFile
	backup := ""
	plans := []session.DownloadPlan{{
		SessionID: m.Session.SessionID, ProjectDir: projectDir, Files: m.Session.Files, Memory: m.Memory, Now: d.Now(),
	}}
	if m.LastUp != nil {
		plans[0].LastUp = m.LastUp.Files
	}
	for _, other := range m.Session.OtherSessionIDs {
		files, err := store.Files(ctx, threadID, other)
		if err != nil {
			return nil, "", err
		}
		plans = append(plans, session.DownloadPlan{SessionID: other, ProjectDir: projectDir, Files: files, Now: d.Now()})
	}
	for i, plan := range plans {
		res, err := session.Download(ctx, store, threadID, plan)
		if err != nil {
			return nil, "", err
		}
		for _, f := range res.Written {
			written = append(written, api.DownloadedFile{SessionID: f.SessionID, RelPath: f.RelPath, SHA256: f.SHA256})
		}
		if i == 0 {
			backup = res.LocalBackup
		}
		for _, c := range res.MemoryConflicts {
			d.say("! A memory file changed on both sides. Yak's copy is saved as %s.", c)
		}
	}
	return written, backup, nil
}

func applyRepos(ctx context.Context, d Deps, repo gitx.Repo, top, mode string, m api.DownManifest, primary api.ManifestRepo, resumeDir string) ([]AppliedRepo, string, error) {
	var res apply.Result
	var err error
	switch mode {
	case "stash":
		res, err = apply.StashAndApply(repo, primary.Branch, primary.HeadSHA)
	case "worktree":
		res, err = apply.Worktree(repo, top, m.Thread.Slug, primary.Branch, primary.HeadSHA)
	default:
		res, err = apply.FastForward(repo, primary.Branch, primary.HeadSHA, true)
	}
	if err != nil {
		return nil, "", userErrorf(err, "Could not apply the thread here: %v", err)
	}
	if res.ConflictsLeft {
		d.say("! Your stashed changes conflict with the thread. The conflict markers are in the files; the stash is kept.")
	}
	d.say("Applied %s at %s (%s)", primary.Branch, shortSHA(primary.HeadSHA), strings.ReplaceAll(res.Mode, "_", "-"))
	applied := []AppliedRepo{{Repo: primary.Repo, Status: "applied", Mode: res.Mode, HeadSHA: primary.HeadSHA, Branch: res.Branch, Path: res.Path}}
	extrasRoot, _ := downFolders(top, m.Thread.Slug)
	for _, extra := range m.Repos {
		if extra.Role == "primary" {
			continue
		}
		applied = append(applied, cloneExtra(d, extrasRoot, extra))
	}
	return applied, res.Path, nil
}

// downFolders returns the folder extras are cloned into and the worktree folder for a dirty primary checkout.
func downFolders(top, slug string) (extrasRoot, worktreeDir string) {
	base := filepath.Clean(top)
	return base + "-" + slug, base + "-yak-" + slug
}

// printDownPlan says where each repo of the thread will land before anything is written on this computer.
func printDownPlan(d Deps, m api.DownManifest, mode, top string) {
	extrasRoot, worktreeDir := downFolders(top, m.Thread.Slug)
	d.say("On this computer:")
	if primary, ok := primaryOf(m); ok {
		where := top
		if mode == "worktree" {
			where = worktreeDir + " (new worktree, " + top + " stays as it is)"
		}
		d.say("  %s -> %s", primary.Repo, where)
	}
	for _, r := range m.Repos {
		switch {
		case r.Role == "primary":
		case r.Status == "failed":
			d.say("  %s: Yak could not push it, it will not be brought down", r.Repo)
		default:
			d.say("  %s -> %s (fresh clone)", r.Repo, filepath.Join(extrasRoot, repoName(r.Repo)))
		}
	}
	if len(m.ContextRepos) > 0 {
		d.say("  Context repos stay on Yak: %s", strings.Join(m.ContextRepos, ", "))
	}
}

func repoName(slug string) string { return slug[strings.LastIndex(slug, "/")+1:] }

func shortSHA(sha string) string {
	if len(sha) > 7 {
		return sha[:7]
	}
	return sha
}

// cloneExtra clones a secondary repo without checking anything out, runs the same clash check, then checks out
// the thread's commit. A failure is reported for that repo and never blocks the primary.
func cloneExtra(d Deps, root string, extra api.ManifestRepo) AppliedRepo {
	name := extra.Repo[strings.LastIndex(extra.Repo, "/")+1:]
	path := filepath.Join(root, name)
	failed := func(reason string) AppliedRepo {
		return AppliedRepo{Repo: extra.Repo, Status: "failed", Mode: "clone", Reason: reason}
	}
	if _, err := os.Lstat(path); err == nil {
		return failed("the folder " + path + " already exists")
	}
	if err := os.MkdirAll(root, 0o755); err != nil {
		return failed(err.Error())
	}
	if _, err := (gitx.Repo{Dir: root}).Run("clone", "--quiet", "--no-checkout", extra.CloneURL, path); err != nil {
		os.RemoveAll(path)
		return failed("clone failed")
	}
	clone := gitx.Repo{Dir: path}
	if extra.HeadSHA != "" {
		report, err := clash.Check(clone, extra.HeadSHA)
		if err != nil || !report.Empty() {
			os.RemoveAll(path)
			return failed("the repo has files this computer cannot hold")
		}
		if _, err := clone.Run("checkout", "--quiet", "-B", extra.Branch, extra.HeadSHA); err != nil {
			os.RemoveAll(path)
			return failed("checkout failed")
		}
	} else if _, err := clone.Run("checkout", "--quiet"); err != nil {
		os.RemoveAll(path)
		return failed("checkout failed")
	}
	d.say("Cloned %s to %s", extra.Repo, path)
	return AppliedRepo{Repo: extra.Repo, Status: "applied", Mode: "clone", HeadSHA: extra.HeadSHA, Branch: extra.Branch, Path: path}
}

func writeNote(d Deps, m api.DownManifest, applied []AppliedRepo, resumeDir, projectDir, tip string) {
	goos, arch := osArch()
	env := m.Environment
	in := NoteInput{
		SandboxOS: env.SandboxOS, SandboxArch: env.SandboxArch, LocalOS: goos, LocalArch: arch,
		Primary: CheckoutMapping{SandboxPath: firstNonEmpty(env.SandboxWorkspacePath, "/workspace"), LocalPath: resumeDir},
		ToSHA:   tip,
	}
	if m.LastUp != nil {
		in.FromSHA = m.LastUp.HeadSHA
	}
	for _, r := range env.Refresh {
		in.Refresh = append(in.Refresh, RefreshRule{Glob: r.Glob, Command: r.Command})
	}
	for _, a := range applied[1:] {
		if a.Status == "applied" {
			name := a.Repo[strings.LastIndex(a.Repo, "/")+1:]
			in.Extras = append(in.Extras, CheckoutMapping{SandboxPath: strings.ReplaceAll(firstNonEmpty(env.ExtrasPathPattern, "/extras/<repo>"), "<repo>", name), LocalPath: a.Path})
		}
	}
	if touched, err := TouchedPaths(filepath.Join(projectDir, m.Session.SessionID+".jsonl")); err == nil {
		in.TouchedPaths = touched
	}
	if err := config.WriteNote(d.ConfigDir, m.Session.SessionID, BuildLocalNote(in)); err != nil {
		d.say("! Could not write the environment note: %v", err)
	}
}

func remember(d Deps, repoSlug, top string) {
	cfg := d.Config
	cfg.Checkouts = map[string]string{}
	for k, v := range d.Config.Checkouts {
		cfg.Checkouts[k] = v
	}
	cfg.Checkouts[repoSlug] = top
	if err := cfg.Save(d.ConfigDir); err != nil {
		d.say("! Could not remember this checkout: %v", err)
	}
}
