package teleport

import (
	"bytes"
	"strings"
	"testing"
	"time"

	"github.com/geocodio/yak/cli/internal/api"
)

// minuteClock makes every sleep take a minute on a fake clock.
func (h *harness) minuteClock() {
	now := time.Date(2026, 11, 13, 9, 0, 0, 0, time.UTC)
	h.deps.Now = func() time.Time { return now }
	h.deps.Sleep = func(time.Duration) { now = now.Add(time.Minute) }
}

func TestDownPrintsRunningStepsWithDetail(t *testing.T) {
	h := newHarness(t)
	s := h.setupDown(true)
	h.srv.ScriptDownStates(42, s.manifest, "preparing", "preparing", "preparing", "preparing", "ready")
	h.srv.ScriptDownProgress(42,
		[]api.Progress{{Step: "wake_sandbox", Status: "running", Detail: "waiting for a free sandbox"}},
		[]api.Progress{{Step: "wake_sandbox", Status: "running", Detail: "waiting for a free sandbox"}},
		[]api.Progress{{Step: "wake_sandbox", Status: "done"}, {Step: "mystery_step", Status: "running"}},
		[]api.Progress{{Step: "wake_sandbox", Status: "done"}, {Step: "mystery_step", Status: "done"}},
	)
	h.minuteClock()
	if _, err := h.down(DownOptions{}); err != nil {
		t.Fatal(err)
	}
	out := h.out.String()
	for _, want := range []string{"Waking the sandbox: waiting for a free sandbox...", "Still waking the sandbox (1m)", "Woke the sandbox", "Mystery step..."} {
		if !strings.Contains(out, want) {
			t.Fatalf("missing %q in:\n%s", want, out)
		}
	}
	if strings.ContainsAny(out, "\r\x1b") {
		t.Fatal("control characters in plain output")
	}
}

func TestDownStalledTeleportIsCancelled(t *testing.T) {
	h := newHarness(t)
	s := h.setupDown(true)
	h.srv.ScriptDownStates(42, s.manifest, "preparing")
	h.minuteClock()
	_, err := h.down(DownOptions{})
	if err == nil || !strings.Contains(err.Error(), "Yak stopped reporting progress 11 minutes ago (last step: Yak is getting the thread ready)") {
		t.Fatalf("got %v", err)
	}
	if reason, _ := h.srv.CancelInfo(h.srv.LastTeleportID()); reason != "error" {
		t.Fatalf("cancel reason %q", reason)
	}
}

func TestDownWaitingForTurnNeverStalls(t *testing.T) {
	h := newHarness(t)
	s := h.setupDown(true)
	states := make([]string, 0, 21)
	for range 20 {
		states = append(states, "waiting_for_turn")
	}
	h.srv.ScriptDownStates(42, s.manifest, append(states, "ready")...)
	h.minuteClock()
	if _, err := h.down(DownOptions{}); err != nil {
		t.Fatal(err)
	}
}

func TestUpStalledCompletionReturnsWithoutCancelling(t *testing.T) {
	h := newHarness(t)
	h.local.Git("switch", "-q", "-c", "feat")
	h.writeTranscript(firstLine)
	h.srv.SlowCompleteForPolls(100)
	h.minuteClock()
	_, err := h.up(PrepareOptions{})
	if err == nil || !strings.Contains(err.Error(), "Yak stopped reporting progress") {
		t.Fatalf("got %v", err)
	}
	if reason, _ := h.srv.CancelInfo(h.srv.LastTeleportID()); reason != "" {
		t.Fatalf("cancelled after complete: %q", reason)
	}
}

func TestRunningLabelFallsBackToHumanizedStep(t *testing.T) {
	if got := runningLabel("warm_the_cache"); got != "Warm the cache" {
		t.Fatalf("got %q", got)
	}
	if got := runningLabel("push"); got != "Pushing the branch" {
		t.Fatalf("got %q", got)
	}
}

func TestLiveStatusRewritesOneLine(t *testing.T) {
	var out bytes.Buffer
	now := time.Date(2026, 11, 13, 9, 0, 0, 0, time.UTC)
	s := newStatus(Deps{Out: &out, Live: true, Now: func() time.Time { return now }})
	s.observe(api.Teleport{State: "preparing", Progress: []api.Progress{{Step: "wake_sandbox", Status: "running", Detail: "waiting for a free sandbox"}}})
	now = now.Add(100 * time.Second)
	s.draw()
	s.observe(api.Teleport{State: "preparing", Progress: []api.Progress{{Step: "wake_sandbox", Status: "done"}}})
	got := out.String()
	for _, want := range []string{"\r\x1b[K⠋ Waking the sandbox: waiting for a free sandbox 1:40 · slow", "✓ Woke the sandbox\n"} {
		if !strings.Contains(got, want) {
			t.Fatalf("missing %q in %q", want, got)
		}
	}
}
