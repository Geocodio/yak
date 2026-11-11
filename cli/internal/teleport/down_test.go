package teleport

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/geocodio/yak/cli/internal/api"
	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/fakeyak"
	"github.com/geocodio/yak/cli/internal/gitx/testrepo"
	"github.com/geocodio/yak/cli/internal/ui"
)

const yakSessionBody = firstLine + "{\"yak\":1}\n"

func sum(s string) string {
	h := sha256.Sum256([]byte(s))
	return hex.EncodeToString(h[:])
}

type scriptPrompter struct {
	choices   []int
	confirms  []bool
	questions []string
	options   [][]ui.Option
}

func (p *scriptPrompter) Confirm(q string, def bool) (bool, error) {
	p.questions = append(p.questions, q)
	if len(p.confirms) == 0 {
		return def, nil
	}
	v := p.confirms[0]
	p.confirms = p.confirms[1:]
	return v, nil
}

func (p *scriptPrompter) Choose(q string, o []ui.Option, def int) (int, error) {
	p.questions = append(p.questions, q)
	p.options = append(p.options, o)
	if len(p.choices) == 0 {
		return def, nil
	}
	v := p.choices[0]
	p.choices = p.choices[1:]
	return v, nil
}

func (p *scriptPrompter) Pick(string, []ui.PickItem) ([]int, error) { return nil, nil }
func (p *scriptPrompter) Edit(_, cur string) (string, error)        { return cur, nil }

type downSetup struct {
	tip      string
	lastUp   string
	manifest api.DownManifest
}

// setupDown puts the thread on Yak at yak/fix. With onLocal the checkout is on yak/fix at the last teleport-up
// commit; without it the thread was never teleported up and the branch does not exist locally.
func (h *harness) setupDown(onLocal bool) *downSetup {
	t := h.t
	s := &downSetup{}
	if onLocal {
		h.local.Git("switch", "-q", "-c", "yak/fix")
		s.lastUp = h.local.Commit(map[string]string{"base.txt": "base\n"}, "up")
		h.local.Git("push", "-q", "-u", "origin", "yak/fix")
	}
	yak := testrepo.Clone(t, h.origin)
	if onLocal {
		yak.Git("switch", "-q", "yak/fix")
	} else {
		yak.Git("switch", "-q", "-c", "yak/fix")
	}
	s.tip = yak.Commit(map[string]string{"yak.txt": "from yak\n"}, "yak work")
	yak.Git("push", "-q", "origin", "yak/fix")

	h.srv.Threads[42] = &fakeyak.Thread{
		ID: 42, Title: "Fix cache", Repo: "acme/widgets", Branch: "yak/fix", Status: "open", OwnerSide: "yak", Visibility: "private",
		ActiveSessionID: testSession, ClaudeAccount: api.ClaudeAccount{Name: "Yak Team"}, URL: h.srv.URL() + "/threads/42",
	}
	h.srv.SeedSessionFile(42, testSession, testSession+".jsonl", []byte(yakSessionBody))
	m := &s.manifest
	m.Thread.ID, m.Thread.Slug, m.Thread.Title, m.Thread.ClaudeAccount = 42, "fix-cache", "Fix cache", api.ClaudeAccount{Name: "Yak Team"}
	m.Repos = []api.ManifestRepo{{
		Repo: "acme/widgets", Role: "primary", CloneURL: h.origin, DefaultBranch: "main", Branch: "yak/fix", HeadSHA: s.tip,
		BranchCreatedByYak: !onLocal, SandboxPath: "/workspace", WIPCommitMade: true, Pushed: true,
	}}
	m.Session.SessionID = testSession
	m.Session.Files = []api.SessionFile{{RelPath: testSession + ".jsonl", Mode: "append", Length: int64(len(yakSessionBody)), SHA256: sum(yakSessionBody)}}
	m.Environment.SandboxOS, m.Environment.SandboxArch, m.Environment.SandboxWorkspacePath = "linux", "x86_64", "/workspace"
	if onLocal {
		m.LastUp = &api.DownLastUp{Branch: "yak/fix", HeadSHA: s.lastUp, Files: []api.SessionFile{{RelPath: testSession + ".jsonl", Length: int64(len(firstLine)), SHA256: sum(firstLine)}}}
		h.writeTranscript(firstLine)
	}
	h.srv.ScriptDownStates(42, *m, "preparing", "ready")
	return s
}

func (h *harness) down(opts DownOptions) (DownResult, error) {
	return Down(context.Background(), h.deps, DownInput{ThreadID: 42, Cwd: h.cwd, Options: opts})
}

func (h *harness) snapshot() string {
	h.t.Helper()
	var b strings.Builder
	for _, args := range [][]string{{"status", "--porcelain=v1", "-uall"}, {"rev-parse", "HEAD"}, {"for-each-ref", "refs/stash"}, {"stash", "list"}} {
		out, _ := h.local.GitErr(args...)
		b.WriteString(out + "\n--\n")
	}
	_ = filepath.WalkDir(h.cwd, func(p string, d fs.DirEntry, err error) error {
		if err == nil && d.IsDir() && d.Name() == ".git" {
			return filepath.SkipDir
		}
		if err == nil && d.Type().IsRegular() {
			data, _ := os.ReadFile(p)
			b.WriteString(p + " " + sum(string(data)) + "\n")
		}
		return nil
	})
	return b.String()
}

func (h *harness) lastRequest() string {
	reqs := h.srv.Requests()
	last := reqs[len(reqs)-1]
	return last.Method + " " + last.Path
}

func TestDownCleanFastForwardAndResume(t *testing.T) {
	h := newHarness(t)
	setup := h.setupDown(true)
	res, err := h.down(DownOptions{})
	if err != nil {
		t.Fatal(err)
	}
	if h.local.Git("rev-parse", "HEAD") != setup.tip || res.ResumeDir != h.cwd || res.SessionID != testSession {
		t.Fatalf("%+v", res)
	}
	got, _ := os.ReadFile(filepath.Join(h.projectDir(h.cwd), testSession+".jsonl"))
	if string(got) != yakSessionBody {
		t.Fatalf("session file %q", got)
	}
	if m, ok := config.ReadMarker(h.deps.ConfigDir, testSession); !ok || m.Owner != "device" || m.StackMessagesURL == "" {
		t.Fatalf("%+v %v", m, ok)
	}
	if _, ok := config.TakeNote(h.deps.ConfigDir, testSession); !ok {
		t.Fatal("no environment note")
	}
	if h.srv.Threads[42].OwnerSide != "device" {
		t.Fatal("ownership did not move")
	}
	if !strings.HasSuffix(h.lastRequest(), "/complete") {
		t.Fatalf("complete was not last: %s", h.lastRequest())
	}
	if !strings.Contains(h.out.String(), "Yak committed its work and pushed yak/fix") {
		t.Fatal(h.out.String())
	}
}

func TestDownWrongRepoStopsLocally(t *testing.T) {
	h := newHarness(t)
	h.setupDown(true)
	h.srv.Threads[42].Repo = "acme/other"
	_, err := h.down(DownOptions{})
	var refusal *Refusal
	if !errors.As(err, &refusal) || refusal.Kind != "wrong_repo" {
		t.Fatalf("got %v", err)
	}
	if h.srv.LastTeleportID() != "" {
		t.Fatal("a teleport was begun")
	}
}

func TestDownRunningTurnPromptsWaitStopCancel(t *testing.T) {
	cases := []struct {
		name    string
		prompt  *scriptPrompter
		turn    string
		refused bool
	}{
		{"wait is the default", &scriptPrompter{}, "wait", false},
		{"stop asks again", &scriptPrompter{choices: []int{1}, confirms: []bool{true}}, "stop", false},
		{"stop declined cancels", &scriptPrompter{choices: []int{1}, confirms: []bool{false}}, "", true},
		{"cancel", &scriptPrompter{choices: []int{2}}, "", true},
	}
	for _, c := range cases {
		t.Run(c.name, func(t *testing.T) {
			h := newHarness(t)
			h.setupDown(true)
			h.srv.Threads[42].TurnRunning = true
			h.deps.Prompter = c.prompt
			_, err := h.down(DownOptions{})
			var refusal *Refusal
			if c.refused {
				if !errors.As(err, &refusal) {
					t.Fatalf("got %v", err)
				}
				if h.srv.LastTeleportID() != "" {
					t.Fatal("a teleport was begun")
				}
				return
			}
			if err != nil {
				t.Fatal(err)
			}
			var last string
			for _, r := range h.requests("POST /api/cli/v1/teleports") {
				if strings.HasSuffix(r.Path, "/teleports") {
					last = string(r.Body)
				}
			}
			if !strings.Contains(last, `"running_turn":"`+c.turn+`"`) {
				t.Fatal(last)
			}
		})
	}
}

func TestDownVisibilityChoiceAsked(t *testing.T) {
	h := newHarness(t)
	h.setupDown(true)
	h.srv.Threads[42].Visibility = "team"
	prompt := &scriptPrompter{choices: []int{1}}
	h.deps.Prompter = prompt
	if _, err := h.down(DownOptions{}); err != nil {
		t.Fatal(err)
	}
	if len(prompt.questions) != 1 || !strings.Contains(prompt.questions[0], "visible to the team") {
		t.Fatalf("%v", prompt.questions)
	}
	if h.srv.Threads[42].Visibility != "private" {
		t.Fatal("private choice was not applied")
	}
}

func pushTip(t *testing.T, h *harness, entries []testrepo.Entry) {
	t.Helper()
	yak := testrepo.Clone(t, h.origin)
	tip := yak.Tip(entries, "")
	yak.Git("push", "-q", "-f", "origin", tip+":refs/heads/yak/fix")
	h.hostileTip = tip
}

func TestDownClashRefusesAndTreeUntouched(t *testing.T) {
	cases := map[string][]testrepo.Entry{
		"case only": {{Path: "src/Mail/Invoice.php", Content: "a"}, {Path: "src/Mail/invoice.php", Content: "b"}},
		"nfd":       {{Path: "café.txt", Content: "a"}, {Path: "café.txt", Content: "b"}},
		"symlink":   {{Path: "bin/run", Mode: "120000", Content: "/etc/passwd"}},
		"dot git":   {{Path: "sub/.git/config", Content: "x"}},
	}
	for name, entries := range cases {
		t.Run(name, func(t *testing.T) {
			h := newHarness(t)
			// Case clashes are only refused where the checkout ignores case, as on macOS.
			h.local.Git("config", "core.ignorecase", "true")
			setup := h.setupDown(true)
			pushTip(t, h, entries)
			setup.manifest.Repos[0].HeadSHA = h.hostileTip
			h.srv.ScriptDownStates(42, setup.manifest, "ready")
			before := h.snapshot()
			_, err := h.down(DownOptions{})
			var refusal *Refusal
			if !errors.As(err, &refusal) || refusal.Kind != "clash" || len(refusal.Paths) == 0 {
				t.Fatalf("got %v", err)
			}
			if after := h.snapshot(); after != before {
				t.Fatalf("tree changed:\n%s\n---\n%s", before, after)
			}
			if reason, _ := h.srv.CancelInfo(h.srv.LastTeleportID()); reason != "clash" {
				t.Fatalf("cancel reason %q", reason)
			}
			if h.srv.Threads[42].OwnerSide != "yak" {
				t.Fatal("ownership moved")
			}
			for _, p := range refusal.Paths {
				if !strings.Contains(h.out.String(), p) {
					t.Fatalf("%q not named in:\n%s", p, h.out.String())
				}
			}
		})
	}
}

func dirty(h *harness) {
	h.local.Write("README.md", "dirty\n")
	h.local.Write("scratch.sql", "select 1;\n")
}

func TestDownDirtyTreeOffersThreeChoicesDefaultCancel(t *testing.T) {
	h := newHarness(t)
	h.setupDown(true)
	dirty(h)
	prompt := &scriptPrompter{}
	h.deps.Prompter = prompt
	before := h.snapshot()
	_, err := h.down(DownOptions{})
	var refusal *Refusal
	if !errors.As(err, &refusal) || refusal.Kind != "dirty_tree" {
		t.Fatalf("got %v", err)
	}
	opts := prompt.options[0]
	if len(opts) != 3 || opts[2].Label != "Cancel" || !strings.Contains(opts[0].Label, "Stash") || !strings.Contains(opts[1].Label, "worktree") {
		t.Fatalf("%+v", opts)
	}
	if h.snapshot() != before {
		t.Fatal("tree changed")
	}
	if reason, _ := h.srv.CancelInfo(h.srv.LastTeleportID()); reason != "dirty_tree" {
		t.Fatalf("cancel reason %q", reason)
	}
	for _, want := range []string{"README.md", "scratch.sql"} {
		if !strings.Contains(h.out.String(), want) {
			t.Fatalf("%s not listed:\n%s", want, h.out.String())
		}
	}
	// A non-interactive prompter takes the same default.
	h2 := newHarness(t)
	h2.setupDown(true)
	dirty(h2)
	if _, err := h2.down(DownOptions{}); !errors.As(err, &refusal) {
		t.Fatalf("got %v", err)
	}
}

func TestDownStashPath(t *testing.T) {
	h := newHarness(t)
	setup := h.setupDown(true)
	dirty(h)
	h.deps.Prompter = &scriptPrompter{choices: []int{0}}
	if _, err := h.down(DownOptions{}); err != nil {
		t.Fatal(err)
	}
	if h.local.Git("rev-parse", "HEAD") != setup.tip {
		t.Fatal("not at the tip")
	}
	if got, _ := os.ReadFile(filepath.Join(h.cwd, "README.md")); string(got) != "dirty\n" {
		t.Fatalf("local change lost: %q", got)
	}
	if _, err := os.Stat(filepath.Join(h.cwd, "scratch.sql")); err != nil {
		t.Fatal("untracked file lost")
	}
	if h.local.Git("stash", "list") != "" {
		t.Fatal("stash left behind")
	}
}

func TestDownWorktreePath(t *testing.T) {
	h := newHarness(t)
	setup := h.setupDown(true)
	dirty(h)
	before := h.snapshot()
	h.deps.Prompter = &scriptPrompter{choices: []int{1}}
	res, err := h.down(DownOptions{})
	if err != nil {
		t.Fatal(err)
	}
	want := h.cwd + "-yak-fix-cache"
	if res.ResumeDir != want {
		t.Fatalf("resume dir %q", res.ResumeDir)
	}
	if h.snapshot() != before {
		t.Fatal("primary checkout changed")
	}
	if sha, _ := gitxRepo(want).Head(); sha != setup.tip {
		t.Fatalf("worktree at %s", sha)
	}
	if _, err := os.Stat(filepath.Join(h.projectDir(want), testSession+".jsonl")); err != nil {
		t.Fatalf("session not in the worktree's project folder: %v", err)
	}
}

func TestDownNeverTeleportedUpRequiresCleanTree(t *testing.T) {
	h := newHarness(t)
	h.setupDown(false)
	h.local.Write("scratch.sql", "x\n")
	_, err := h.down(DownOptions{})
	var refusal *Refusal
	if !errors.As(err, &refusal) || refusal.Kind != "dirty_tree" {
		t.Fatalf("got %v", err)
	}
}

func TestDownBranchCreatedLocallyWhenYakMadeIt(t *testing.T) {
	h := newHarness(t)
	setup := h.setupDown(false)
	if _, err := h.down(DownOptions{}); err != nil {
		t.Fatal(err)
	}
	if branch, _, _ := h.local.CurrentBranch(); branch != "yak/fix" || h.local.Git("rev-parse", "HEAD") != setup.tip {
		t.Fatalf("on %s", branch)
	}
	applied := h.requests("/complete")
	var body api.CompleteDownRequest
	_ = json.Unmarshal(applied[0].Body, &body)
	if body.Applied[0].Status != "applied" || body.Applied[0].Mode != "fast_forward" || body.Applied[0].Path != h.cwd {
		t.Fatalf("%+v", body.Applied)
	}
}

func TestDownDivergedSessionBackedUp(t *testing.T) {
	h := newHarness(t)
	h.setupDown(true)
	h.writeTranscript(firstLine + "{\"local\":1}\n")
	if _, err := h.down(DownOptions{}); err != nil {
		t.Fatal(err)
	}
	dir := h.projectDir(h.cwd)
	backup, _ := os.ReadFile(filepath.Join(dir, testSession+".local-2026-11-13.jsonl"))
	if string(backup) != firstLine+"{\"local\":1}\n" {
		t.Fatalf("backup %q", backup)
	}
	if got, _ := os.ReadFile(filepath.Join(dir, testSession+".jsonl")); string(got) != yakSessionBody {
		t.Fatalf("session %q", got)
	}
	if !strings.Contains(h.out.String(), testSession+".local-2026-11-13.jsonl") {
		t.Fatal(h.out.String())
	}
	if c := h.requests("/complete"); !strings.Contains(string(c[0].Body), `"local_session_backup":"`+testSession+`.local-2026-11-13.jsonl"`) {
		t.Fatal(string(c[0].Body))
	}
}

func TestDownDownloadHashMismatchCancelsAndRenamesNothing(t *testing.T) {
	h := newHarness(t)
	setup := h.setupDown(true)
	setup.manifest.Session.Files[0].SHA256 = sum("something else")
	h.srv.ScriptDownStates(42, setup.manifest, "ready")
	before := h.snapshot()
	if _, err := h.down(DownOptions{}); err == nil {
		t.Fatal("hash mismatch accepted")
	}
	if h.snapshot() != before {
		t.Fatal("local files changed")
	}
	if got, _ := os.ReadFile(filepath.Join(h.projectDir(h.cwd), testSession+".jsonl")); string(got) != firstLine {
		t.Fatalf("session replaced: %q", got)
	}
	if reason, _ := h.srv.CancelInfo(h.srv.LastTeleportID()); reason != "error" {
		t.Fatalf("cancel reason %q", reason)
	}
	if len(h.requests("/complete")) != 0 || h.srv.Threads[42].OwnerSide != "yak" {
		t.Fatal("ownership moved or complete was sent")
	}
}

func TestDownOwnershipMovesOnlyAfterLocalApply(t *testing.T) {
	h := newHarness(t)
	setup := h.setupDown(true)
	h.local.Write("README.md", "dirty\n")
	// The local branch has a commit the thread lacks, so stash cannot apply and the teleport stops before complete.
	h.local.Git("stash", "-q")
	h.local.Commit(map[string]string{"local.txt": "mine\n"}, "local work")
	setup.manifest.LastUp.HeadSHA = h.local.Git("rev-parse", "HEAD")
	h.srv.ScriptDownStates(42, setup.manifest, "ready")
	h.local.Write("scratch.sql", "x\n")
	h.deps.Prompter = &scriptPrompter{choices: []int{0}}
	_, err := h.down(DownOptions{Resolution: "stash"})
	if err == nil {
		t.Fatal("apply should have been refused")
	}
	if len(h.requests("/complete")) != 0 || h.srv.Threads[42].OwnerSide != "yak" {
		t.Fatal("ownership moved without a local apply")
	}
}

func TestDownCtrlCCancels(t *testing.T) {
	h := newHarness(t)
	h.setupDown(true)
	h.srv.ScriptDownStates(42, api.DownManifest{}, "waiting_for_turn")
	ctx, cancel := context.WithCancel(context.Background())
	h.deps.Sleep = func(time.Duration) { cancel() }
	_, err := Down(ctx, h.deps, DownInput{ThreadID: 42, Cwd: h.cwd})
	if !errors.Is(err, context.Canceled) {
		t.Fatalf("got %v", err)
	}
	if reason, _ := h.srv.CancelInfo(h.srv.LastTeleportID()); reason != "user_cancelled" {
		t.Fatalf("cancel reason %q", reason)
	}
	if h.srv.Threads[42].OwnerSide != "yak" {
		t.Fatal("ownership moved")
	}
}

func TestDownFromLinkConfirmsEveryTime(t *testing.T) {
	h := newHarness(t)
	h.setupDown(true)
	prompt := &scriptPrompter{confirms: []bool{false}}
	h.deps.Prompter = prompt
	if _, err := h.down(DownOptions{FromLink: true}); err == nil {
		t.Fatal("a declined link teleported")
	}
	if h.srv.LastTeleportID() != "" {
		t.Fatal("a teleport was begun")
	}
	if !strings.Contains(h.out.String(), `Teleport thread 42 "Fix cache" (acme/widgets) to this computer?`) {
		t.Fatal(h.out.String())
	}
	h.deps.Prompter = &scriptPrompter{confirms: []bool{true}}
	if _, err := h.down(DownOptions{FromLink: true}); err != nil {
		t.Fatal(err)
	}
	// The default answer is no.
	h2 := newHarness(t)
	h2.setupDown(true)
	if _, err := h2.down(DownOptions{FromLink: true}); err == nil {
		t.Fatal("default answer was yes")
	}
}

func TestDownRememberedCheckoutSaved(t *testing.T) {
	h := newHarness(t)
	h.setupDown(true)
	if _, err := h.down(DownOptions{}); err != nil {
		t.Fatal(err)
	}
	cfg, err := config.Load(h.deps.ConfigDir)
	if err != nil || cfg.Checkouts["acme/widgets"] != h.cwd {
		t.Fatalf("%+v %v", cfg, err)
	}
}

// addExtra puts a second repo on Yak at branch yak/fix with the given files and adds it to the manifest.
func (h *harness) addExtra(s *downSetup, slug string, files map[string]string, refresh ...api.RefreshRule) (origin, head string) {
	h.t.Helper()
	origin = h.srv.AddRepoWithOrigin(h.t, slug)
	yak := testrepo.Clone(h.t, origin)
	yak.Git("switch", "-q", "-c", "yak/fix")
	head = yak.Commit(files, "yak work")
	yak.Git("push", "-q", "origin", "yak/fix")
	s.manifest.Repos = append(s.manifest.Repos, api.ManifestRepo{
		Repo: slug, Role: "changes", CloneURL: origin, DefaultBranch: "main", Branch: "yak/fix", HeadSHA: head,
		SandboxPath: "/extras/" + slug[strings.LastIndex(slug, "/")+1:], Pushed: true, Refresh: refresh,
	})
	h.srv.ScriptDownStates(42, s.manifest, "preparing", "ready")
	return origin, head
}

func (h *harness) rescript(s *downSetup) {
	h.srv.ScriptDownStates(42, s.manifest, "preparing", "ready")
}

func TestDownPlanShowsBothFoldersForWorktree(t *testing.T) {
	h := newHarness(t)
	s := h.setupDown(true)
	h.addExtra(s, "acme/api", map[string]string{"a.txt": "a\n"})
	s.manifest.ContextRepos = []string{"acme/docs"}
	h.rescript(s)
	dirty(h)
	h.deps.Prompter = &scriptPrompter{choices: []int{1}}
	if _, err := h.down(DownOptions{}); err != nil {
		t.Fatal(err)
	}
	extrasRoot, worktree := downFolders(h.cwd, "fix-cache")
	out := h.out.String()
	if extrasRoot == worktree || !strings.Contains(out, worktree) || !strings.Contains(out, filepath.Join(extrasRoot, "api")) {
		t.Fatalf("plan missing folders:\n%s", out)
	}
	if strings.Index(out, "On this computer:") > strings.Index(out, "Applied ") {
		t.Fatalf("plan came after the apply:\n%s", out)
	}
}

func TestDownPlanListsContextReposAsStayingOnYak(t *testing.T) {
	h := newHarness(t)
	s := h.setupDown(true)
	s.manifest.ContextRepos = []string{"acme/docs", "acme/site"}
	h.rescript(s)
	if _, err := h.down(DownOptions{}); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(h.out.String(), "Context repos stay on Yak: acme/docs, acme/site") {
		t.Fatal(h.out.String())
	}
}

func TestDownPlanComesBeforeAnyWrite(t *testing.T) {
	h := newHarness(t)
	s := h.setupDown(true)
	h.addExtra(s, "acme/api", map[string]string{"a.txt": "a\n"})
	if _, err := h.down(DownOptions{}); err != nil {
		t.Fatal(err)
	}
	out := h.out.String()
	plan, cloned := strings.Index(out, "On this computer:"), strings.Index(out, "Cloned ")
	if plan < 0 || cloned < 0 || plan > cloned {
		t.Fatalf("plan must come before the first write:\n%s", out)
	}
}

func TestDownCancelledLeavesNoExtrasFolder(t *testing.T) {
	h := newHarness(t)
	s := h.setupDown(true)
	h.addExtra(s, "acme/api", map[string]string{"a.txt": "a\n"})
	dirty(h)
	before := h.snapshot()
	h.deps.Prompter = &scriptPrompter{choices: []int{2}}
	if _, err := h.down(DownOptions{}); err == nil {
		t.Fatal("expected cancel")
	}
	extrasRoot, _ := downFolders(h.cwd, "fix-cache")
	if _, err := os.Stat(extrasRoot); err == nil || h.snapshot() != before {
		t.Fatal("something was written")
	}
}

func (h *harness) appliedBody() map[string]api.AppliedRepo {
	h.t.Helper()
	reqs := h.requests("/complete")
	var body api.CompleteDownRequest
	if err := json.Unmarshal(reqs[len(reqs)-1].Body, &body); err != nil {
		h.t.Fatal(err)
	}
	out := map[string]api.AppliedRepo{}
	for _, a := range body.Applied {
		out[a.Repo] = a
	}
	return out
}

func TestDownExtraFolderExistsFailsOnlyThatRepo(t *testing.T) {
	h := newHarness(t)
	s := h.setupDown(true)
	h.addExtra(s, "acme/api", map[string]string{"a.txt": "a\n"})
	h.addExtra(s, "acme/web", map[string]string{"w.txt": "w\n"})
	root, _ := downFolders(h.cwd, "fix-cache")
	taken := filepath.Join(root, "api")
	if err := os.MkdirAll(taken, 0o755); err != nil {
		t.Fatal(err)
	}
	if _, err := h.down(DownOptions{}); err != nil {
		t.Fatal(err)
	}
	got := h.appliedBody()
	if got["acme/widgets"].Status != "applied" || got["acme/web"].Status != "applied" {
		t.Fatalf("%+v", got)
	}
	if got["acme/api"].Status != "failed" || !strings.Contains(got["acme/api"].Reason, "the folder "+taken+" already exists") {
		t.Fatalf("%+v", got["acme/api"])
	}
	if _, err := os.Stat(filepath.Join(root, "web", "w.txt")); err != nil {
		t.Fatal(err)
	}
}

func TestDownExtraClashNamesPathsAndLeavesNothingBehind(t *testing.T) {
	h := newHarness(t)
	s := h.setupDown(true)
	origin := h.srv.AddRepoWithOrigin(t, "acme/api")
	yak := testrepo.Clone(t, origin)
	yak.Git("switch", "-q", "-c", "yak/fix")
	head := yak.Tip([]testrepo.Entry{{Path: "Readme.md", Content: "1"}, {Path: "README.md", Content: "2"}}, "")
	yak.Git("update-ref", "refs/heads/yak/fix", head)
	yak.Git("push", "-q", "origin", "yak/fix")
	s.manifest.Repos = append(s.manifest.Repos, api.ManifestRepo{Repo: "acme/api", Role: "changes", CloneURL: origin, Branch: "yak/fix", HeadSHA: head})
	h.rescript(s)
	if _, err := h.down(DownOptions{}); err != nil {
		t.Fatal(err)
	}
	a := h.appliedBody()["acme/api"]
	root, _ := downFolders(h.cwd, "fix-cache")
	if a.Status != "failed" || !strings.Contains(a.Reason, "these paths clash: ") || !strings.Contains(a.Reason, "README.md") {
		t.Fatalf("%+v", a)
	}
	if _, err := os.Stat(filepath.Join(root, "api")); err == nil {
		t.Fatal("clone left behind")
	}
}

func TestDownExtraCloneDeniedIsReported(t *testing.T) {
	h := newHarness(t)
	s := h.setupDown(true)
	h.addExtra(s, "acme/api", map[string]string{"a.txt": "a\n"})
	s.manifest.Repos[1].CloneURL = filepath.Join(t.TempDir(), "missing.git")
	h.rescript(s)
	if _, err := h.down(DownOptions{}); err != nil {
		t.Fatal(err)
	}
	if a := h.appliedBody()["acme/api"]; a.Status != "failed" || a.Reason != "clone denied for acme/api" {
		t.Fatalf("%+v", a)
	}
}

func TestDownSkipsRepoFailedOnYak(t *testing.T) {
	h := newHarness(t)
	s := h.setupDown(true)
	h.addExtra(s, "acme/api", map[string]string{"a.txt": "a\n"})
	s.manifest.Repos[1].Status = "failed"
	s.manifest.Repos[1].Failure = &api.Failure{Code: "push_failed", Message: "Yak could not push yak/fix"}
	h.rescript(s)
	if _, err := h.down(DownOptions{}); err != nil {
		t.Fatal(err)
	}
	a := h.appliedBody()["acme/api"]
	root, _ := downFolders(h.cwd, "fix-cache")
	if a.Status != "skipped" || a.Reason != "Yak could not push it: Yak could not push yak/fix" {
		t.Fatalf("%+v", a)
	}
	if _, err := os.Stat(root); err == nil {
		t.Fatal("extras folder created for a failed repo")
	}
}

func TestDownSummaryNamesAppliedAndNotApplied(t *testing.T) {
	h := newHarness(t)
	s := h.setupDown(true)
	h.addExtra(s, "acme/api", map[string]string{"a.txt": "a\n"})
	h.addExtra(s, "acme/web", map[string]string{"w.txt": "w\n"})
	s.manifest.Repos[2].Status = "failed"
	s.manifest.Repos[2].Failure = &api.Failure{Message: "no push"}
	h.rescript(s)
	if _, err := h.down(DownOptions{}); err != nil {
		t.Fatal(err)
	}
	out := h.out.String()
	if !strings.Contains(out, "Applied: acme/widgets, acme/api\n") || !strings.Contains(out, "Not applied: acme/web (Yak could not push it: no push)\n") {
		t.Fatal(out)
	}
}

func TestDownNeverUsesAnExistingCheckoutOfAnExtra(t *testing.T) {
	h := newHarness(t)
	s := h.setupDown(true)
	origin, head := h.addExtra(s, "acme/api", map[string]string{"a.txt": "a\n"})
	elsewhere := testrepo.Clone(t, origin)
	elsewhere.Write("mine.txt", "local work\n")
	h.deps.Config.Checkouts = map[string]string{"acme/api": elsewhere.Dir}
	if _, err := h.down(DownOptions{}); err != nil {
		t.Fatal(err)
	}
	root, _ := downFolders(h.cwd, "fix-cache")
	if sha, _ := gitxRepo(filepath.Join(root, "api")).Head(); sha != head {
		t.Fatalf("extra at %s", sha)
	}
	if _, err := os.Stat(filepath.Join(root, "api", "mine.txt")); err == nil {
		t.Fatal("used the other checkout")
	}
}

func TestDownWritesNoteWithExtrasAndContextRepos(t *testing.T) {
	h := newHarness(t)
	s := h.setupDown(true)
	h.addExtra(s, "acme/api", map[string]string{"a.txt": "a\n"}, api.RefreshRule{Glob: "composer.lock", Command: "composer install"})
	s.manifest.ContextRepos = []string{"acme/docs"}
	h.rescript(s)
	if _, err := h.down(DownOptions{}); err != nil {
		t.Fatal(err)
	}
	note, _ := config.TakeNote(h.deps.ConfigDir, testSession)
	root, _ := downFolders(h.cwd, "fix-cache")
	for _, want := range []string{"In " + filepath.Join(root, "api") + ": composer install (fresh clone, nothing is installed)", "Context repos. acme/docs stayed on Yak"} {
		if !strings.Contains(note, want) {
			t.Fatalf("missing %q in:\n%s", want, note)
		}
	}
}
