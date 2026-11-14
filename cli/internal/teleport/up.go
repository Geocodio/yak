package teleport

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/geocodio/yak/cli/internal/api"
	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/gitx"
	"github.com/geocodio/yak/cli/internal/session"
)

// UpInput is what the person asked for.
type UpInput struct {
	Cwd       string
	SessionID string
	Message   string
	AddDirs   []string
	Options   PrepareOptions
}

// UpResult describes the thread after a completed teleport up.
type UpResult struct {
	ThreadID   int
	ThreadURL  string
	TeleportID string
	Branch     string
}

type upRepo struct {
	repo     gitx.Repo
	dir      string
	slug     string
	role     string
	remote   string
	prepared Prepared
}

const maxPolls = 900

// Up moves the session and its branch to Yak. The default branch never moves: changes on it are snapshotted as a
// detached WIP commit that only becomes a branch once Yak has assigned one. Nothing local changes until Yak has
// confirmed the thread, and the read-only marker is written last, only after a completed state.
func Up(ctx context.Context, d Deps, in UpInput) (UpResult, error) {
	primaryRepo := gitx.Repo{Dir: in.Cwd}
	top, err := primaryRepo.Toplevel()
	if err != nil {
		return UpResult{}, userErrorf(err, "This folder is not a git checkout, so there is nothing to teleport.")
	}
	primaryRepo.Dir = top

	sid, transcript, err := pickSession(d, in)
	if err != nil {
		return UpResult{}, err
	}
	projectDir := filepath.Dir(transcript)
	titleHint := firstPrompt(transcript)

	item, onYak, err := resolveCheckout(ctx, d, primaryRepo)
	if err != nil {
		return UpResult{}, err
	}
	if !onYak {
		return UpResult{}, userErrorf(nil, "This repo is not on Yak, so the session cannot move. Add it to Yak first.")
	}
	repos := []*upRepo{{repo: primaryRepo, dir: top, slug: *item.Repo, role: "primary", remote: item.Remote}}

	var missing []string
	var extras []*upRepo
	var plan []upPlanEntry
	for _, dir := range in.AddDirs {
		extra, ok, err := resolveAddDir(ctx, d, dir)
		if err != nil {
			return UpResult{}, err
		}
		if !ok {
			missing = append(missing, dir)
			continue
		}
		role, reason, err := classifyAddDir(extra.repo)
		if err != nil {
			return UpResult{}, userErrorf(err, "Could not read %s: %v", dir, err)
		}
		extra.role = role
		extras = append(extras, extra)
		plan = append(plan, upPlanEntry{Dir: dir, Slug: extra.slug, Role: role, Reason: reason})
	}
	printUpPlan(d, plan)
	for i, extra := range extras {
		yes, err := d.Prompter.Confirm(fmt.Sprintf("Bring %s (%s) to Yak?", extra.slug, extra.role), true)
		if err != nil {
			return UpResult{}, err
		}
		if !yes {
			missing = append(missing, plan[i].Dir)
			continue
		}
		repos = append(repos, extra)
	}

	var existing *api.Thread
	var lookup api.ThreadResponse
	if err := d.API.Do(ctx, "GET", "/threads/by-session/"+sid, nil, &lookup); err == nil {
		existing = &lookup.Thread
		if existing.Status != "closed" {
			if existing.OwnerSide == "yak" {
				if done, ok := alreadyOnYak(ctx, d, sid, existing); ok {
					return done, nil
				}
				return UpResult{}, friendly(&api.Error{Status: 409, Code: "thread_owned_by_yak", Details: map[string]any{"thread_id": existing.ID}})
			}
			if branch, detached, _ := primaryRepo.CurrentBranch(); !detached && existing.Branch != "" && branch != existing.Branch {
				return UpResult{}, friendly(&api.Error{Status: 409, Code: "branch_mismatch", Details: map[string]any{"thread_branch": existing.Branch, "current_branch": branch}})
			}
		}
	} else if !api.IsCode(err, "thread_not_found") {
		return UpResult{}, friendly(err)
	}

	for _, r := range repos {
		if r.role == "context" {
			continue
		}
		opts := in.Options
		if r.role != "primary" {
			opts = PrepareOptions{Interactive: in.Options.Interactive}
		}
		if r.prepared, err = PrepareUp(d.Prompter, r.repo, opts); err != nil {
			discardAllDetached(repos)
			return UpResult{}, err
		}
		if r.prepared.WIPCommit != "" {
			d.say("Committed %d files", committedCount(r.repo, r.prepared.WIPCommit))
		}
	}

	gitName, _ := primaryRepo.Config("user.name")
	gitEmail, _ := primaryRepo.Config("user.email")
	goos, arch := osArch()
	begin := api.BeginUpRequest{
		Direction: "up", SessionID: sid, TitleHint: titleHint, GitName: truncate(gitName, 200), GitEmail: truncate(gitEmail, 200),
		LocalPath: top, OS: goos, Arch: arch, MissingDirectories: missing,
	}
	if begin.MissingDirectories == nil {
		begin.MissingDirectories = []string{}
	}
	for _, r := range repos {
		entry := api.BeginRepo{Repo: r.slug, Role: r.role, LocalPath: r.dir}
		if r.role != "context" {
			branch, head := r.prepared.Branch, r.prepared.Head
			entry.Branch, entry.HeadSHA, entry.OnDefaultBranch = &branch, &head, r.prepared.OnDefaultBranch
		}
		begin.Repos = append(begin.Repos, entry)
	}
	var started api.BeginUpResponse
	if err := d.API.Do(ctx, "POST", "/teleports", begin, &started); err != nil {
		discardAllDetached(repos)
		return UpResult{}, friendly(err)
	}

	pushes := map[string]*upRepo{}
	var completeRepos []api.CompleteUpRepo
	for _, planned := range started.Repos {
		var local *upRepo
		for _, r := range repos {
			if r.slug == planned.Repo && r.role == planned.Role {
				local = r
			}
		}
		if local == nil {
			continue
		}
		if local.role == "context" {
			completeRepos = append(completeRepos, api.CompleteUpRepo{Repo: planned.Repo})
			continue
		}
		if planned.Branch == "" {
			continue
		}
		if err := prepareBranch(local, planned); err != nil {
			discardAllDetached(repos)
			d.cancel(ctx, started.TeleportID, "error", err.Error())
			return UpResult{}, userErrorf(err, "Could not switch to the branch Yak assigned: %v", err)
		}
		head, err := local.repo.Head()
		if err != nil {
			d.cancel(ctx, started.TeleportID, "error", err.Error())
			return UpResult{}, err
		}
		local.prepared.Branch, local.prepared.Head = planned.Branch, head
		pushes[planned.Repo+"/"+planned.Role] = local
		completeRepos = append(completeRepos, api.CompleteUpRepo{Repo: planned.Repo, Branch: planned.Branch, HeadSHA: head})
	}
	push := func(r *upRepo) error {
		return d.gitNetwork(ctx, r.repo, "push", r.remote, "refs/heads/"+r.prepared.Branch+":refs/heads/"+r.prepared.Branch)
	}
	for _, r := range repos {
		if pushes[r.slug+"/"+r.role] == nil {
			continue
		}
		if r.remote, err = remoteFor(r.repo, r.remote); err != nil {
			d.cancel(ctx, started.TeleportID, "error", err.Error())
			return UpResult{}, err
		}
		if err := push(r); err != nil {
			d.cancel(ctx, started.TeleportID, "error", "push failed")
			if isCancelled(err) {
				return UpResult{}, err
			}
			return UpResult{}, userErrorf(err, "Could not push %s to %s: %v. This checkout is now on %s, the session was not uploaded, and Yak did not take the thread. Run yak teleport again to retry.", r.prepared.Branch, r.slug, err, r.prepared.Branch)
		}
		d.say("Pushed %s to github.com/%s", r.prepared.Branch, r.slug)
	}

	var since time.Time
	if started.Thread.FirstTeleportedAt != nil {
		since, _ = time.Parse(time.RFC3339, *started.Thread.FirstTeleportedAt)
	}
	store := &session.Store{Client: d.API}
	uploaded, err := session.Upload(ctx, store, started.Thread.ID, sid, projectDir, since, nil)
	if err != nil {
		if errors.Is(err, session.ErrDiverged) || errors.Is(err, session.ErrCheckpointMismatch) {
			d.cancel(ctx, started.TeleportID, "diverged", err.Error())
			return UpResult{}, userErrorf(err, "Yak's copy of this session differs from the one on this computer, so nothing was overwritten. The teleport is cancelled.")
		}
		if isCancelled(err) {
			return UpResult{}, err
		}
		if _, isAPI := api.AsError(err); isAPI {
			return UpResult{}, resumable(err)
		}
		return UpResult{}, userErrorf(err, "The upload was interrupted (%v). Run yak teleport again to resume it.", err)
	}
	d.say("Uploaded the session (%s)", humanBytes(uploaded.Bytes))

	done, err := completeUp(ctx, d, started, api.CompleteUpRequest{Repos: completeRepos, Message: in.Message}, func() error {
		for _, r := range repos {
			if pushes[r.slug+"/"+r.role] != nil {
				if err := push(r); err != nil {
					return err
				}
			}
		}
		return nil
	})
	var unconfirmed *unconfirmedError
	if errors.As(err, &unconfirmed) {
		err = nil
	}
	if err != nil {
		return UpResult{}, err
	}

	marker := config.Marker{
		ThreadID: started.Thread.ID, TeleportID: started.TeleportID, Owner: "yak", ThreadURL: started.Thread.URL,
		BaseURL: d.Config.BaseURL, WrittenAt: d.Now(),
	}
	if err := config.WriteMarker(d.ConfigDir, sid, marker); err != nil {
		return UpResult{}, userErrorf(err, "Yak has the thread, but this computer could not record it: %v", err)
	}
	if unconfirmed != nil {
		d.say("Yak accepted the teleport and has the thread, so this session is now read-only. This computer lost the connection (%v) before Yak confirmed it was ready.", unconfirmed.cause)
		d.say("Check on it at %s", started.Thread.URL)
		return UpResult{ThreadID: started.Thread.ID, ThreadURL: started.Thread.URL, TeleportID: started.TeleportID, Branch: completeRepos[0].Branch}, nil
	}
	if done.Sandbox.State == "waiting_for_slot" {
		d.say("Yak has no free sandbox right now. The thread is on Yak and will start when one is free.")
	}
	d.say("Yak has the thread. This session is now read-only.")
	reportUpRepos(d, repos, done.Sandbox.Repos, missing)
	d.say("Yak runs it under the %s Claude account; turns you run here use your local Claude login.", started.Thread.ClaudeAccount.Name)
	d.say("Continue at %s", started.Thread.URL)
	return UpResult{ThreadID: started.Thread.ID, ThreadURL: started.Thread.URL, TeleportID: started.TeleportID, Branch: completeRepos[0].Branch}, nil
}

// discardAllDetached unstages the snapshots of repos whose WIP commit never became a branch.
func discardAllDetached(repos []*upRepo) {
	for _, r := range repos {
		if r.prepared.Detached {
			discardDetached(r.repo)
		}
	}
}

// unconfirmedError means Yak accepted the complete call but the polls that would show the sandbox ready kept
// failing on the network.
type unconfirmedError struct{ cause error }

func (e *unconfirmedError) Error() string {
	return "Yak accepted the teleport but did not confirm it finished"
}
func (e *unconfirmedError) Unwrap() error { return e.cause }

// alreadyOnYak recognises a thread that an earlier run of this command handed to Yak, when its answer never
// reached this computer. The marker is written now so hooks treat the session as read-only.
func alreadyOnYak(ctx context.Context, d Deps, sid string, thread *api.Thread) (UpResult, bool) {
	if _, has := config.ReadMarker(d.ConfigDir, sid); has || thread.LastTeleportUp == nil {
		return UpResult{}, false
	}
	var earlier api.Teleport
	if err := d.API.Do(ctx, "GET", "/teleports/"+thread.LastTeleportUp.TeleportID, nil, &earlier); err != nil {
		return UpResult{}, false
	}
	if earlier.Direction != "up" || earlier.State != "completed" {
		return UpResult{}, false
	}
	marker := config.Marker{
		ThreadID: thread.ID, TeleportID: earlier.TeleportID, Owner: "yak", ThreadURL: thread.URL, BaseURL: d.Config.BaseURL, WrittenAt: d.Now(),
	}
	if err := config.WriteMarker(d.ConfigDir, sid, marker); err != nil {
		d.say("! Could not record that Yak has the thread: %v", err)
	}
	d.say("Yak already has this thread from an earlier teleport. This session is now read-only.")
	d.say("Continue at %s", thread.URL)
	return UpResult{ThreadID: thread.ID, ThreadURL: thread.URL, TeleportID: earlier.TeleportID, Branch: thread.Branch}, true
}

func completeUp(ctx context.Context, d Deps, started api.BeginUpResponse, req api.CompleteUpRequest, rePush func() error) (api.CompleteUpResponse, error) {
	path := "/teleports/" + started.TeleportID + "/complete"
	var resp api.CompleteUpResponse
	repushed := false
	for {
		err := d.API.Do(ctx, "POST", path, req, &resp)
		if err == nil {
			break
		}
		if api.IsCode(err, "push_not_visible") && !repushed {
			repushed = true
			if pushErr := rePush(); pushErr != nil {
				d.cancel(ctx, started.TeleportID, "error", "push failed")
				return resp, userErrorf(pushErr, "Yak could not see the pushed branch and pushing again failed: %v", pushErr)
			}
			continue
		}
		if api.IsCode(err, "push_not_visible") {
			return resp, userErrorf(err, "Yak still cannot see the pushed branch on GitHub. Nothing changed here. Check access to the repo and run yak teleport again.")
		}
		if e, ok := api.AsError(err); ok && e.Code == "teleport_failed" {
			return resp, userErrorf(err, "Yak could not finish the teleport: %s. Nothing changed on this computer.", firstNonEmpty(detail(e, "message"), e.Message))
		}
		if isCancelled(err) {
			return resp, err
		}
		if api.IsTransient(err) {
			return resp, userErrorf(err, "Yak did not answer the final step (%v). It may already have the thread. Run yak teleport again to check and resume.", err)
		}
		return resp, resumable(err)
	}
	failures := 0
	for polls := 0; resp.Teleport.State != "completed"; polls++ {
		if resp.Teleport.State == "failed" || resp.Teleport.State == "cancelled" || resp.Teleport.State == "expired" || polls > maxPolls {
			msg := "the teleport did not finish"
			if resp.Teleport.Failure != nil {
				msg = resp.Teleport.Failure.Message
			}
			return resp, userErrorf(nil, "Yak could not finish the teleport: %s. Nothing changed on this computer.", msg)
		}
		if err := d.sleep(ctx, time.Duration(max(resp.Teleport.PollAfterSeconds, 1))*time.Second); err != nil {
			return resp, err
		}
		var next api.Teleport
		if err := d.API.Do(ctx, "GET", "/teleports/"+started.TeleportID, nil, &next); err != nil {
			if !api.IsTransient(err) {
				return resp, resumable(err)
			}
			if failures++; failures >= maxPollFailures {
				return resp, &unconfirmedError{cause: err}
			}
			continue
		}
		failures = 0
		resp.Teleport = next
	}
	return resp, nil
}

func firstNonEmpty(values ...string) string {
	for _, v := range values {
		if v != "" {
			return v
		}
	}
	return ""
}

func truncate(text string, n int) string {
	if utf8.RuneCountInString(text) <= n {
		return text
	}
	return string([]rune(text)[:n])
}

func humanBytes(n int64) string {
	if n < 1024 {
		return fmt.Sprintf("%d B", n)
	}
	return fmt.Sprintf("%d KB", (n+1023)/1024)
}

func committedCount(repo gitx.Repo, sha string) int {
	out, err := repo.Run("show", "--name-only", "--pretty=format:", sha)
	if err != nil {
		return 0
	}
	n := 0
	for _, line := range strings.Split(out, "\n") {
		if strings.TrimSpace(line) != "" {
			n++
		}
	}
	return n
}

// prepareBranch puts the checkout on the branch Yak assigned: created at the prepared head when Yak says so,
// otherwise it must already be the current branch. It never pushes and never touches the default branch.
func prepareBranch(r *upRepo, planned api.BeginUpRepo) error {
	current, _, _ := r.repo.CurrentBranch()
	if current == planned.Branch {
		return nil
	}
	if !planned.CreateBranch {
		return fmt.Errorf("the checkout is on %q, not %q", current, planned.Branch)
	}
	if sha, err := r.repo.Run("rev-parse", "--verify", "-q", "refs/heads/"+planned.Branch); err == nil {
		if sha != r.prepared.Head {
			return fmt.Errorf("branch %s already exists at another commit", planned.Branch)
		}
		_, err := r.repo.Run("switch", planned.Branch)
		return err
	}
	return SwitchToNewBranch(r.repo, planned.Branch, r.prepared.Head)
}

func resolveAddDir(ctx context.Context, d Deps, dir string) (*upRepo, bool, error) {
	repo := gitx.Repo{Dir: dir}
	top, err := repo.Toplevel()
	if err != nil {
		return nil, false, nil
	}
	repo.Dir = top
	item, ok, err := resolveCheckout(ctx, d, repo)
	if err != nil || !ok {
		return nil, false, err
	}
	return &upRepo{repo: repo, dir: top, slug: *item.Repo, remote: item.Remote}, true, nil
}

// pickSession returns the session id and the path of its transcript. A session found by guessing is confirmed
// with the person, showing its first prompt.
func pickSession(d Deps, in UpInput) (string, string, error) {
	configDir := session.ClaudeConfigDir(d.Env, d.Home)
	sid := in.SessionID
	if sid == "" {
		sid = d.Env("CLAUDE_SESSION_ID")
	}
	if sid != "" {
		path, err := session.FindSessionFile(configDir, in.Cwd, sid)
		if err != nil {
			return "", "", userErrorf(err, "Could not find the session %s on this computer.", sid)
		}
		return sid, path, nil
	}
	matches, _ := filepath.Glob(filepath.Join(configDir, "projects", session.ProjectFolderName(in.Cwd), "*.jsonl"))
	type candidate struct {
		id, path string
		mod      time.Time
	}
	var found []candidate
	for _, m := range matches {
		id := strings.TrimSuffix(filepath.Base(m), ".jsonl")
		if info, err := os.Stat(m); err == nil && session.ValidSessionID(id) {
			found = append(found, candidate{id, m, info.ModTime()})
		}
	}
	if len(found) == 0 {
		return "", "", userErrorf(nil, "No Claude session found for this folder. Pass --session <id>.")
	}
	sort.Slice(found, func(i, j int) bool { return found[i].mod.After(found[j].mod) })
	title := firstPrompt(found[0].path)
	if title == "" {
		title = found[0].id
	}
	ok, err := d.Prompter.Confirm(fmt.Sprintf("Teleport the most recent session, \"%s\"?", truncate(title, 80)), true)
	if err != nil {
		return "", "", err
	}
	if !ok {
		return "", "", userErrorf(nil, "Cancelled. Pass --session <id> to pick another session.")
	}
	return found[0].id, found[0].path, nil
}

// firstPrompt is the first thing the person typed in the transcript, trimmed to 200 characters.
func firstPrompt(path string) string {
	f, err := os.Open(path)
	if err != nil {
		return ""
	}
	defer f.Close()
	reader := bufio.NewReader(f)
	for {
		line, err := reader.ReadBytes('\n')
		var row struct {
			Type    string `json:"type"`
			IsMeta  bool   `json:"isMeta"`
			Message struct {
				Role    string          `json:"role"`
				Content json.RawMessage `json:"content"`
			} `json:"message"`
		}
		if json.Unmarshal(line, &row) == nil && row.Type == "user" && !row.IsMeta {
			var text string
			var blocks []struct {
				Type string `json:"type"`
				Text string `json:"text"`
			}
			if json.Unmarshal(row.Message.Content, &text) == nil {
			} else if json.Unmarshal(row.Message.Content, &blocks) == nil {
				for _, b := range blocks {
					if b.Type == "text" {
						text = b.Text
						break
					}
				}
			}
			if text = strings.TrimSpace(text); text != "" {
				return truncate(text, 200)
			}
		}
		if err != nil {
			return ""
		}
	}
}

// reportUpRepos prints what became of each repo and which added directories were not uploaded.
func reportUpRepos(d Deps, repos []*upRepo, sandboxRepos []api.SandboxRepo, missing []string) {
	if len(repos) > 1 {
		for _, r := range repos {
			var failure *api.SandboxRepo
			for i, sr := range sandboxRepos {
				if sr.Repo == r.slug && sr.Status == "failed" {
					failure = &sandboxRepos[i]
				}
			}
			switch {
			case failure != nil:
				d.say("! %s could not be set up on Yak: %s", r.slug, failure.Reason)
			case r.role == "context":
				d.say("%s: context, not changed", r.slug)
			default:
				d.say("%s: changes, on %s", r.slug, r.prepared.Branch)
			}
		}
	}
	if len(missing) > 0 {
		d.say("Not uploaded (not on Yak): %s", strings.Join(missing, ", "))
	}
}
