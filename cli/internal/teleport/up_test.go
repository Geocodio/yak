package teleport

import (
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
