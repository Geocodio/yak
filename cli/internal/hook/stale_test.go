package hook

import (
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
		if !strings.Contains(out, `"decision":"block"`) || !strings.Contains(out, "another terminal") || !strings.Contains(out, "marker clear "+sid) {
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
