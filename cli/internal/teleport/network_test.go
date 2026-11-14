package teleport

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/fakeyak"
)

func (h *harness) readyUp() {
	h.local.Git("switch", "-q", "-c", "feat")
	h.writeTranscript(firstLine)
}

func TestUpLostCompleteResponseIsRetried(t *testing.T) {
	h := newHarness(t)
	h.readyUp()
	h.srv.InjectFault(fakeyak.Fault{Match: "/complete", Kind: fakeyak.FaultDropAfter, Times: 1})
	res, err := h.up(PrepareOptions{})
	if err != nil {
		t.Fatal(err)
	}
	if m, ok := config.ReadMarker(h.deps.ConfigDir, testSession); !ok || m.ThreadID != res.ThreadID {
		t.Fatalf("%+v %v", m, ok)
	}
}

func TestUpCompleteAnswerNeverArrivesThenRerunRecognisesIt(t *testing.T) {
	h := newHarness(t)
	h.readyUp()
	h.srv.InjectFault(fakeyak.Fault{Match: "/complete", Kind: fakeyak.FaultDropAfter, Times: 5})
	_, err := h.up(PrepareOptions{})
	if err == nil || !strings.Contains(err.Error(), "Run yak teleport again") {
		t.Fatalf("got %v", err)
	}
	if _, ok := config.ReadMarker(h.deps.ConfigDir, testSession); ok {
		t.Fatal("marker written without an answer")
	}
	h.out.Reset()
	res, err := h.up(PrepareOptions{})
	if err != nil {
		t.Fatalf("rerun hit a dead end: %v", err)
	}
	if m, ok := config.ReadMarker(h.deps.ConfigDir, testSession); !ok || m.Owner != "yak" || m.ThreadID != res.ThreadID {
		t.Fatalf("%+v %v", m, ok)
	}
	if !strings.Contains(h.out.String(), "already has this thread") {
		t.Fatalf("output:\n%s", h.out.String())
	}
}

func TestUpFlakyPollsAfterCompleteRecover(t *testing.T) {
	h := newHarness(t)
	h.readyUp()
	h.srv.SlowCompleteForPolls(2)
	h.srv.InjectFault(fakeyak.Fault{Match: "GET /api/cli/v1/teleports/", Kind: fakeyak.FaultStatus, Status: 503, Times: 7})
	if _, err := h.up(PrepareOptions{}); err != nil {
		t.Fatal(err)
	}
	if _, ok := config.ReadMarker(h.deps.ConfigDir, testSession); !ok {
		t.Fatal("no marker")
	}
}

func TestUpPollsKeepFailingAfterCompleteWritesMarkerAndSaysYakHasTheThread(t *testing.T) {
	h := newHarness(t)
	h.readyUp()
	h.srv.SlowCompleteForPolls(2)
	h.srv.InjectFault(fakeyak.Fault{Match: "GET /api/cli/v1/teleports/", Kind: fakeyak.FaultStatus, Status: 503, Times: 1000})
	res, err := h.up(PrepareOptions{})
	if err != nil {
		t.Fatal(err)
	}
	if _, ok := config.ReadMarker(h.deps.ConfigDir, testSession); !ok {
		t.Fatal("no marker")
	}
	out := h.out.String()
	if !strings.Contains(out, "Yak accepted the teleport and has the thread") || !strings.Contains(out, res.ThreadURL) {
		t.Fatalf("output:\n%s", out)
	}
}

func TestUpPushIsRetried(t *testing.T) {
	h := newHarness(t)
	h.readyUp()
	hook := filepath.Join(h.origin, "hooks", "pre-receive")
	script := "#!/bin/sh\nif [ -f \"$0.seen\" ]; then exit 0; fi\ntouch \"$0.seen\"\necho flaky >&2\nexit 1\n"
	if err := os.MkdirAll(filepath.Dir(hook), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(hook, []byte(script), 0o755); err != nil {
		t.Fatal(err)
	}
	if _, err := h.up(PrepareOptions{}); err != nil {
		t.Fatal(err)
	}
	if h.originRef("refs/heads/feat") == "" {
		t.Fatal("feat was not pushed")
	}
	if len(h.sleeps) == 0 {
		t.Fatal("no pause between push attempts")
	}
}

func TestUpPushFailureMessageIsTruthful(t *testing.T) {
	h := newHarness(t)
	h.local.Git("switch", "-q", "-c", "side")
	h.local.Git("switch", "-q", "main")
	h.writeTranscript(firstLine)
	hook := filepath.Join(h.origin, "hooks", "pre-receive")
	if err := os.MkdirAll(filepath.Dir(hook), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(hook, []byte("#!/bin/sh\nexit 1\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	_, err := h.up(PrepareOptions{})
	if err == nil || strings.Contains(err.Error(), "Nothing was uploaded") || !strings.Contains(err.Error(), "This checkout is now on yak/") || !strings.Contains(err.Error(), "Run yak teleport again") {
		t.Fatalf("got %v", err)
	}
	if branch, _, _ := h.local.CurrentBranch(); !strings.HasPrefix(branch, "yak/") {
		t.Fatalf("checkout is on %s", branch)
	}
}

func TestDownFlakyPollsRecover(t *testing.T) {
	h := newHarness(t)
	setup := h.setupDown(true)
	h.srv.ScriptDownStates(42, setup.manifest, "preparing", "ready")
	h.srv.InjectFault(fakeyak.Fault{Match: "GET /api/cli/v1/teleports/", Kind: fakeyak.FaultStatus, Status: 503, Times: 7})
	if _, err := h.down(DownOptions{}); err != nil {
		t.Fatal(err)
	}
}

func TestDownLostConnectionDoesNotCancelThePreparedTeleport(t *testing.T) {
	h := newHarness(t)
	setup := h.setupDown(true)
	h.srv.ScriptDownStates(42, setup.manifest, "preparing", "ready")
	h.srv.InjectFault(fakeyak.Fault{Match: "GET /api/cli/v1/teleports/", Kind: fakeyak.FaultStatus, Status: 503, Times: 1000})
	_, err := h.down(DownOptions{})
	if err == nil || !strings.Contains(err.Error(), "Run yak teleport again") {
		t.Fatalf("got %v", err)
	}
	if len(h.requests("/cancel")) != 0 {
		t.Fatal("a flaky link cancelled the teleport")
	}
}

func TestDownDownloadNetworkFailureDoesNotCancel(t *testing.T) {
	h := newHarness(t)
	setup := h.setupDown(true)
	h.srv.ScriptDownStates(42, setup.manifest, "ready")
	h.srv.InjectFault(fakeyak.Fault{Match: "/session/read", Kind: fakeyak.FaultDrop, Times: 1000})
	if _, err := h.down(DownOptions{}); err == nil {
		t.Fatal("download should fail")
	}
	if len(h.requests("/cancel")) != 0 {
		t.Fatal("a network failure cancelled the teleport")
	}
}

func TestSleepReturnsAtOnceWhenContextEnds(t *testing.T) {
	h := newHarness(t)
	release := make(chan struct{})
	t.Cleanup(func() { close(release) })
	h.deps.Sleep = func(time.Duration) { <-release }
	ctx, cancel := contextWithCancel()
	cancel()
	if err := h.deps.sleep(ctx, time.Hour); err == nil {
		t.Fatal("sleep ignored the cancelled context")
	}
}

func contextWithCancel() (context.Context, context.CancelFunc) {
	return context.WithCancel(context.Background())
}
