package hook

import (
	"os"
	"strings"
	"testing"

	"github.com/geocodio/yak/cli/internal/config"
)

func writeDeviceMarker(t *testing.T, r *rig, epoch string) {
	t.Helper()
	m := config.Marker{ThreadID: 42, ThreadPublicID: "k0000042", TeleportID: "tp2", Owner: "device", ResumeEpoch: epoch}
	if err := config.WriteMarker(r.dir, sid, m); err != nil {
		t.Fatal(err)
	}
}

func TestStaleTerminalIsBlockedWhenTheThreadWasResumedElsewhere(t *testing.T) {
	r := newRig(t)
	writeDeviceMarker(t, r, "tp2")
	prompt := Input{SessionID: sid, Prompt: "keep going"}

	for name, env := range map[string]func(string) string{
		"no epoch":    func(string) string { return "" },
		"older epoch": func(string) string { return "tp1" },
		"no env":      nil,
	} {
		r.deps.Env = env
		out := UserPromptSubmit(r.deps, prompt)
		if !strings.Contains(out, `"decision":"block"`) || !strings.Contains(out, "another terminal") || !strings.Contains(out, "YAK_TELEPORT_EPOCH=tp2 claude --resume "+sid) {
			t.Errorf("%s: stale terminal not blocked with a clear message: %q", name, out)
		}
	}

	r.deps.Env = func(key string) string {
		if key == EpochEnv {
			return "tp2"
		}
		return ""
	}
	if out := UserPromptSubmit(r.deps, prompt); strings.Contains(out, `"decision"`) {
		t.Fatalf("the terminal yak teleport started was blocked: %q", out)
	}
}

func TestMarkerWithoutEpochNeverBlocksADeviceSession(t *testing.T) {
	r := newRig(t)
	writeDeviceMarker(t, r, "")
	if out := UserPromptSubmit(r.deps, Input{SessionID: sid, Prompt: "hi"}); strings.Contains(out, `"decision"`) {
		t.Fatalf("legacy marker blocked: %q", out)
	}
}

func TestYakOwnedBlockNamesThePublicThreadID(t *testing.T) {
	r := newRig(t)
	m := config.Marker{ThreadID: 42, ThreadPublicID: "k0000042", TeleportID: "tp1", Owner: "yak"}
	if err := config.WriteMarker(r.dir, sid, m); err != nil {
		t.Fatal(err)
	}
	out := UserPromptSubmit(r.deps, Input{SessionID: sid, Prompt: "hi"})
	if !strings.Contains(out, "teleport k0000042 ") || strings.Contains(out, "teleport 42") {
		t.Fatalf("%q", out)
	}
}

func TestStaleTerminalTakeover(t *testing.T) {
	const thisClaude, otherClaude = 200, 100
	r := newRig(t)
	r.deps.Env = func(string) string { return "" }
	r.deps.ClaudePID = func() int { return thisClaude }
	prompt := Input{SessionID: sid, Prompt: "keep going"}
	write := func(launchPID int) {
		m := config.Marker{ThreadID: 42, TeleportID: "tp2", Owner: "device", ResumeEpoch: "tp2", LaunchPID: launchPID}
		if err := config.WriteMarker(r.dir, sid, m); err != nil {
			t.Fatal(err)
		}
	}
	blocked := func() bool { return strings.Contains(UserPromptSubmit(r.deps, prompt), `"decision":"block"`) }

	r.deps.ProcessAlive = func(int) bool { return true }
	write(thisClaude)
	if blocked() {
		t.Error("the claude that launched the session was blocked")
	}
	write(otherClaude)
	if !blocked() {
		t.Error("a live original was taken over")
	}
	write(0)
	r.deps.ProcessAlive = func(int) bool { return false }
	if !blocked() {
		t.Error("a marker without a launch pid allowed a takeover")
	}

	write(otherClaude)
	if blocked() {
		t.Fatal("a dead original was not taken over")
	}
	if m, _ := config.ReadMarker(r.dir, sid); m.LaunchPID != thisClaude {
		t.Errorf("marker not rewritten: %+v", m)
	}
	r.deps.ProcessAlive = func(int) bool { return true }
	if blocked() {
		t.Error("the new owner was blocked on its next prompt")
	}
}

func TestProcessAlive(t *testing.T) {
	if !ProcessAlive(os.Getpid()) || ProcessAlive(1<<22+12345) {
		t.Fatal("liveness check is wrong")
	}
}
