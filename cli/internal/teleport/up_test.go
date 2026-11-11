package teleport

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/geocodio/yak/cli/internal/api"
	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/fakeyak"
	"github.com/geocodio/yak/cli/internal/gitx/testrepo"
)

func TestUpHappyPathFromFeatureBranch(t *testing.T) {
	h := newHarness(t)
	h.local.Git("switch", "-q", "-c", "feat")
	head := h.local.Commit(map[string]string{"a.txt": "a"}, "work")
	h.writeTranscript(firstLine)
	res, err := h.up(PrepareOptions{})
	if err != nil {
		t.Fatal(err)
	}
	if res.Branch != "feat" || res.ThreadID == 0 || res.TeleportID == "" {
		t.Fatalf("%+v", res)
	}
	if h.originRef("refs/heads/feat") != head {
		t.Fatal("feat was not pushed")
	}
	if m, ok := config.ReadMarker(h.deps.ConfigDir, testSession); !ok || m.Owner != "yak" || m.ThreadID != res.ThreadID {
		t.Fatalf("%+v %v", m, ok)
	}
	out := h.out.String()
	for _, want := range []string{"Pushed feat to github.com/acme/widgets", "Uploaded the session (", "Yak has the thread. This session is now read-only.", "Continue at ", "local Claude login"} {
		if !strings.Contains(out, want) {
			t.Fatalf("missing %q in:\n%s", want, out)
		}
	}
}

func TestUpFromMainCreatesYakBranchAndNeverPushesMain(t *testing.T) {
	h := newHarness(t)
	mainBefore := h.originRef("refs/heads/main")
	h.writeTranscript(firstLine)
	res, err := h.up(PrepareOptions{})
	if err != nil {
		t.Fatal(err)
	}
	if res.Branch != "yak/fix-the-geocode-cache" {
		t.Fatalf("%+v", res)
	}
	if branch, _, _ := h.local.CurrentBranch(); branch != res.Branch {
		t.Fatalf("checkout is on %s", branch)
	}
	if h.originRef("refs/heads/"+res.Branch) == "" || h.originRef("refs/heads/main") != mainBefore {
		t.Fatal("wrong refs on origin")
	}
	for _, r := range h.srv.Requests() {
		if strings.Contains(string(r.Body), "refs/heads/main") {
			t.Fatal("main was named in a request")
		}
	}
}

func TestUpDirtyTreeCommitsWIPThenProceeds(t *testing.T) {
	h := newHarness(t)
	h.local.Git("switch", "-q", "-c", "feat")
	h.local.Write("README.md", "changed\n")
	h.writeTranscript(firstLine)
	if _, err := h.up(PrepareOptions{CommitTracked: true}); err != nil {
		t.Fatal(err)
	}
	if msg := h.local.Git("log", "-1", "--format=%s"); !strings.HasPrefix(msg, "WIP") {
		t.Fatalf("last commit %q", msg)
	}
	if status := h.local.Git("status", "--porcelain"); status != "" {
		t.Fatalf("dirty: %s", status)
	}
	if !strings.Contains(h.out.String(), "Committed 1 files") {
		t.Fatal(h.out.String())
	}
}

func TestUpPushFailureCancelsAndUploadsNothing(t *testing.T) {
	h := newHarness(t)
	h.local.Git("switch", "-q", "-c", "feat")
	h.local.Git("remote", "set-url", "--push", "origin", filepath.Join(t.TempDir(), "gone.git"))
	h.writeTranscript(firstLine)
	_, err := h.up(PrepareOptions{})
	if err == nil {
		t.Fatal("push failure was ignored")
	}
	if n := len(h.requests("/session/")); n != 0 {
		t.Fatalf("%d session requests", n)
	}
	if reason, _ := h.srv.CancelInfo(h.srv.LastTeleportID()); reason != "error" {
		t.Fatalf("cancel reason %q", reason)
	}
	if _, ok := config.ReadMarker(h.deps.ConfigDir, testSession); ok {
		t.Fatal("marker written")
	}
}

func TestUpDivergedCancelsWithReasonDiverged(t *testing.T) {
	h := newHarness(t)
	h.local.Git("switch", "-q", "-c", "feat")
	h.srv.Threads[50] = &fakeyak.Thread{
		ID: 50, Repo: "acme/widgets", Branch: "feat", Status: "open", OwnerSide: "device", ActiveSessionID: testSession,
		OwnerDevice: &api.OwnerDevice{ID: h.device.Device.ID, Name: "work mac", IsThisDevice: true},
	}
	h.srv.SeedSessionFile(50, testSession, testSession+".jsonl", []byte(firstLine+"{\"x\":1}\n{\"y\":2}\n"))
	h.writeTranscript(firstLine)
	_, err := h.up(PrepareOptions{})
	if err == nil || !strings.Contains(err.Error(), "differs") {
		t.Fatalf("got %v", err)
	}
	if reason, _ := h.srv.CancelInfo(h.srv.LastTeleportID()); reason != "diverged" {
		t.Fatalf("cancel reason %q", reason)
	}
	if got := string(h.srv.SessionFile(50, testSession, testSession+".jsonl")); got != firstLine+"{\"x\":1}\n{\"y\":2}\n" {
		t.Fatalf("server copy was overwritten: %q", got)
	}
}

func TestUpResumesAfterConnectionDrop(t *testing.T) {
	h := newHarness(t)
	h.local.Git("switch", "-q", "-c", "feat")
	h.writeTranscript(firstLine)
	sub := filepath.Join(h.projectDir(h.cwd), testSession, "subagents", "a.jsonl")
	if err := os.MkdirAll(filepath.Dir(sub), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(sub, []byte("{\"s\":1}\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	h.srv.FailAppendsAfter(1, 500)
	if _, err := h.up(PrepareOptions{}); err == nil {
		t.Fatal("first run should fail")
	}
	if _, ok := config.ReadMarker(h.deps.ConfigDir, testSession); ok {
		t.Fatal("marker written after a failed run")
	}
	firstID := h.srv.LastTeleportID()
	if _, err := h.up(PrepareOptions{}); err != nil {
		t.Fatal(err)
	}
	if h.srv.LastTeleportID() != firstID {
		t.Fatal("second run opened a new teleport")
	}
	appended := map[string]int{}
	for _, r := range h.requests("POST /api/cli/v1/threads/") {
		if strings.HasSuffix(r.Path, "/append") {
			var b struct {
				RelPath string `json:"rel_path"`
			}
			_ = json.Unmarshal(r.Body, &b)
			appended[b.RelPath]++
		}
	}
	if appended[testSession+".jsonl"] != 1 {
		t.Fatalf("the transcript was re-sent: %v", appended)
	}
}

func TestUpMarkerWrittenOnlyAfterCompleted(t *testing.T) {
	h := newHarness(t)
	h.local.Git("switch", "-q", "-c", "feat")
	h.writeTranscript(firstLine)
	h.srv.FailNextComplete("sandbox_failed", "the sandbox would not start")
	_, err := h.up(PrepareOptions{})
	if err == nil || !strings.Contains(err.Error(), "the sandbox would not start") {
		t.Fatalf("got %v", err)
	}
	if _, ok := config.ReadMarker(h.deps.ConfigDir, testSession); ok {
		t.Fatal("marker written for a failed teleport")
	}
}

func TestUpPollsWhilePreparingThenWritesMarker(t *testing.T) {
	h := newHarness(t)
	h.local.Git("switch", "-q", "-c", "feat")
	h.writeTranscript(firstLine)
	h.srv.SlowCompleteForPolls(2)
	if _, err := h.up(PrepareOptions{}); err != nil {
		t.Fatal(err)
	}
	if len(h.sleeps) != 2 {
		t.Fatalf("sleeps %v", h.sleeps)
	}
	if _, ok := config.ReadMarker(h.deps.ConfigDir, testSession); !ok {
		t.Fatal("no marker")
	}
}

func TestUpWaitingForSlotMessage(t *testing.T) {
	h := newHarness(t)
	h.local.Git("switch", "-q", "-c", "feat")
	h.writeTranscript(firstLine)
	h.srv.SetSandboxState("waiting_for_slot")
	if _, err := h.up(PrepareOptions{}); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(h.out.String(), "Yak has no free sandbox right now. The thread is on Yak and will start when one is free.") {
		t.Fatal(h.out.String())
	}
}

func TestUpRepoNotOnYakStopsBeforeAnyWrite(t *testing.T) {
	h := newHarness(t)
	h.local.Git("remote", "set-url", "origin", testrepo.Bare(t))
	h.local.Write("README.md", "dirty\n")
	h.writeTranscript(firstLine)
	head := h.local.Git("rev-parse", "HEAD")
	_, err := h.up(PrepareOptions{CommitTracked: true})
	if err == nil || !strings.Contains(err.Error(), "not on Yak") {
		t.Fatalf("got %v", err)
	}
	for _, r := range h.srv.Requests() {
		if r.Method != "GET" && !strings.HasSuffix(r.Path, "/repos/resolve") {
			t.Fatalf("write request %s %s", r.Method, r.Path)
		}
	}
	if h.local.Git("rev-parse", "HEAD") != head {
		t.Fatal("a commit was made")
	}
}

func TestUpOwnedByYakMessage(t *testing.T) {
	h := newHarness(t)
	h.srv.Threads[77] = &fakeyak.Thread{ID: 77, Repo: "acme/widgets", Status: "open", OwnerSide: "yak", ActiveSessionID: testSession}
	h.writeTranscript(firstLine)
	_, err := h.up(PrepareOptions{})
	if err == nil || !strings.Contains(err.Error(), "yak teleport 77") {
		t.Fatalf("got %v", err)
	}
}

func TestUp426StopsAndChangesNothing(t *testing.T) {
	h := newHarness(t)
	h.local.Git("switch", "-q", "-c", "feat")
	h.local.Write("README.md", "dirty\n")
	h.writeTranscript(firstLine)
	head := h.local.Git("rev-parse", "HEAD")
	h.srv.MinProtocol = 2
	_, err := h.up(PrepareOptions{CommitTracked: true})
	if !errors.Is(err, api.ErrProtocolTooOld) {
		t.Fatalf("got %v", err)
	}
	if h.local.Git("rev-parse", "HEAD") != head {
		t.Fatal("a commit was made")
	}
	if _, ok := config.ReadMarker(h.deps.ConfigDir, testSession); ok {
		t.Fatal("marker written")
	}
}

func TestUpMessageStartsTurn(t *testing.T) {
	h := newHarness(t)
	h.local.Git("switch", "-q", "-c", "feat")
	h.writeTranscript(firstLine)
	_, err := Up(context.Background(), h.deps, UpInput{Cwd: h.cwd, SessionID: testSession, Message: "continue with the cache tests"})
	if err != nil {
		t.Fatal(err)
	}
	complete := h.requests("/complete")
	if len(complete) != 1 || !strings.Contains(string(complete[0].Body), "continue with the cache tests") {
		t.Fatalf("%+v", complete)
	}
}

func TestUpRevokedMidUploadStopsWithRevokedError(t *testing.T) {
	h := newHarness(t)
	h.local.Git("switch", "-q", "-c", "feat")
	h.writeTranscript(firstLine)
	revoked := false
	h.srv.OnRequest = func(r *http.Request) {
		if !revoked && strings.HasSuffix(r.URL.Path, "/append") {
			revoked = true
			h.srv.RevokeDevice(h.device.Device.ID)
		}
	}
	_, err := h.up(PrepareOptions{})
	if !api.IsCode(err, "device_revoked") {
		t.Fatalf("got %v", err)
	}
	if _, ok := config.ReadMarker(h.deps.ConfigDir, testSession); ok {
		t.Fatal("marker written")
	}
}

func TestUpAddDirNotYakIsMissingDirectoryNotUploaded(t *testing.T) {
	h := newHarness(t)
	h.local.Git("switch", "-q", "-c", "feat")
	h.writeTranscript(firstLine)
	scratch := testrepo.Clone(t, testrepo.Bare(t))
	_, err := Up(context.Background(), h.deps, UpInput{Cwd: h.cwd, SessionID: testSession, AddDirs: []string{scratch.Dir, t.TempDir()}})
	if err != nil {
		t.Fatal(err)
	}
	begin := h.requests("POST /api/cli/v1/teleports")
	var body api.BeginUpRequest
	if err := json.Unmarshal(begin[0].Body, &body); err != nil {
		t.Fatal(err)
	}
	if len(body.Repos) != 1 || len(body.MissingDirectories) != 2 || body.MissingDirectories[0] != scratch.Dir {
		t.Fatalf("%+v", body)
	}
}

// snapshotPrompter records what had been printed when each question was asked.
type snapshotPrompter struct {
	scriptPrompter
	out  *bytes.Buffer
	seen []string
}

func (p *snapshotPrompter) Confirm(q string, def bool) (bool, error) {
	p.seen = append(p.seen, p.out.String())
	return p.scriptPrompter.Confirm(q, def)
}

// addYakRepo registers acme/api on the fake Yak and returns a clean checkout of its pushed main plus its origin.
func addYakRepo(t *testing.T, h *harness) (*testrepo.Repo, string) {
	t.Helper()
	origin := h.srv.AddRepoWithOrigin(t, "acme/api")
	seed := testrepo.Clone(t, origin)
	seed.Git("switch", "-q", "-c", "main")
	seed.Commit(map[string]string{"api.txt": "api\n"}, "initial")
	seed.Git("push", "-q", "-u", "origin", "main")
	return testrepo.Clone(t, origin), origin
}

func (h *harness) upAddDirs(opts PrepareOptions, dirs ...string) (UpResult, error) {
	h.local.Git("switch", "-q", "-c", "feat")
	h.writeTranscript(firstLine)
	return Up(context.Background(), h.deps, UpInput{Cwd: h.cwd, SessionID: testSession, AddDirs: dirs, Options: opts})
}

func yakBranches(t *testing.T, origin string) string {
	t.Helper()
	out, err := gitxRepo(origin).Run("for-each-ref", "--format=%(refname)", "refs/heads/yak/")
	if err != nil {
		t.Fatal(err)
	}
	return out
}

func allRefs(t *testing.T, origin string) string {
	t.Helper()
	out, err := gitxRepo(origin).Run("for-each-ref")
	if err != nil {
		t.Fatal(err)
	}
	return out
}

func TestUpAddDirDirtyYakRepoBecomesChangesAndIsPushed(t *testing.T) {
	h := newHarness(t)
	extra, origin := addYakRepo(t, h)
	extra.Write("api.txt", "edited\n")
	res, err := h.upAddDirs(PrepareOptions{CommitTracked: true}, extra.Dir)
	if err != nil {
		t.Fatal(err)
	}
	sent := h.srv.UpRepos(t, res.TeleportID)
	if len(sent) != 2 || sent[1].Role != "changes" || sent[1].Branch == nil || sent[1].HeadSHA == nil {
		t.Fatalf("%+v", sent)
	}
	if subject, _ := gitxRepo(extra.Dir).Run("log", "-1", "--format=%s"); subject != DefaultWIPMessage {
		t.Fatalf("no WIP commit: %q", subject)
	}
	if yakBranches(t, origin) == "" {
		t.Fatalf("no yak branch pushed:\n%s", allRefs(t, origin))
	}
}

func TestUpAddDirCleanYakRepoBecomesContextAndIsNeverPushed(t *testing.T) {
	h := newHarness(t)
	extra, origin := addYakRepo(t, h)
	before, headBefore := allRefs(t, origin), extra.Git("rev-parse", "HEAD")
	res, err := h.upAddDirs(PrepareOptions{}, extra.Dir)
	if err != nil {
		t.Fatal(err)
	}
	sent := h.srv.UpRepos(t, res.TeleportID)
	if len(sent) != 2 || sent[1].Role != "context" || sent[1].Branch != nil || sent[1].HeadSHA != nil {
		t.Fatalf("%+v", sent)
	}
	if allRefs(t, origin) != before || extra.Git("rev-parse", "HEAD") != headBefore {
		t.Fatal("context repo changed")
	}
}

func TestUpAddDirContextRepoNeverReceivesACommit(t *testing.T) {
	h := newHarness(t)
	extra, origin := addYakRepo(t, h)
	before := extra.Git("rev-list", "--all", "--count")
	if _, err := h.upAddDirs(PrepareOptions{CommitTracked: true}, extra.Dir); err != nil {
		t.Fatal(err)
	}
	if extra.Git("rev-list", "--all", "--count") != before || yakBranches(t, origin) != "" {
		t.Fatal("context repo received a commit or ref")
	}
}

func TestUpAddDirDeclinedRepoIsListedMissing(t *testing.T) {
	h := newHarness(t)
	extra, _ := addYakRepo(t, h)
	h.deps.Prompter = &scriptPrompter{confirms: []bool{false}}
	res, err := h.upAddDirs(PrepareOptions{}, extra.Dir)
	if err != nil {
		t.Fatal(err)
	}
	if sent := h.srv.UpRepos(t, res.TeleportID); len(sent) != 1 {
		t.Fatalf("%+v", sent)
	}
	var body api.BeginUpRequest
	if err := json.Unmarshal(h.requests("POST /api/cli/v1/teleports")[0].Body, &body); err != nil {
		t.Fatal(err)
	}
	if len(body.MissingDirectories) != 1 || body.MissingDirectories[0] != extra.Dir {
		t.Fatalf("%+v", body.MissingDirectories)
	}
}

func TestUpAddDirPrintsPlanBeforeAsking(t *testing.T) {
	h := newHarness(t)
	extra, _ := addYakRepo(t, h)
	extra.Write("api.txt", "edited\n")
	p := &snapshotPrompter{out: h.out}
	h.deps.Prompter = p
	if _, err := h.upAddDirs(PrepareOptions{CommitTracked: true}, extra.Dir); err != nil {
		t.Fatal(err)
	}
	var asked string
	for i, q := range p.questions {
		if strings.Contains(q, "acme/api") {
			asked = p.seen[i]
		}
	}
	for _, want := range []string{"acme/api", "changes", "uncommitted changes"} {
		if !strings.Contains(asked, want) {
			t.Fatalf("plan missing %q before the question:\n%s", want, asked)
		}
	}
}

func TestUpAddDirChangesRepoFollowsCleanTreeRules(t *testing.T) {
	h := newHarness(t)
	extra, _ := addYakRepo(t, h)
	extra.Commit(map[string]string{".env": "AWS_SECRET_ACCESS_KEY=abcdefghijklmnopqrstuvwxyz0123456789ABCD\n"}, "add env")
	extra.Write(".env", "AWS_SECRET_ACCESS_KEY=abcdefghijklmnopqrstuvwxyz0123456789ABCE\n")
	_, err := h.upAddDirs(PrepareOptions{CommitTracked: true}, extra.Dir)
	var refusal *Refusal
	if !errors.As(err, &refusal) {
		t.Fatalf("got %v", err)
	}
	if refusal.Kind != "secret_excluded" {
		t.Fatalf("kind %s", refusal.Kind)
	}
	if n := len(h.requests("POST /api/cli/v1/teleports")); n != 0 {
		t.Fatalf("%d teleports begun", n)
	}
}

func TestUpPrintsEachRepoOutcome(t *testing.T) {
	h := newHarness(t)
	extra, _ := addYakRepo(t, h)
	h.srv.SetSandboxRepos(api.SandboxRepo{Repo: "acme/api", Role: "context", Status: "failed", Reason: "clone failed"})
	if _, err := h.upAddDirs(PrepareOptions{}, extra.Dir); err != nil {
		t.Fatal(err)
	}
	out := h.out.String()
	for _, want := range []string{"acme/widgets: changes, on feat", "! acme/api could not be set up on Yak: clone failed"} {
		if !strings.Contains(out, want) {
			t.Fatalf("missing %q in:\n%s", want, out)
		}
	}
	h2 := newHarness(t)
	extra2, _ := addYakRepo(t, h2)
	if _, err := h2.upAddDirs(PrepareOptions{}, extra2.Dir); err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(h2.out.String(), "acme/api: context, not changed") {
		t.Fatalf("missing context line:\n%s", h2.out.String())
	}
}

func TestUpListsMissingDirectoriesAtTheEnd(t *testing.T) {
	h := newHarness(t)
	scratch := testrepo.Clone(t, testrepo.Bare(t))
	if _, err := h.upAddDirs(PrepareOptions{}, scratch.Dir); err != nil {
		t.Fatal(err)
	}
	out := h.out.String()
	if !strings.Contains(out, "Not uploaded (not on Yak): "+scratch.Dir) || strings.Index(out, "Not uploaded") < strings.Index(out, "read-only") {
		t.Fatalf("%s", out)
	}
}
