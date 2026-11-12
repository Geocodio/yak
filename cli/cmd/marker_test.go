package cmd

import (
	"strings"
	"testing"

	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/hook"
)

func TestMarkerClearValidatesUUID(t *testing.T) {
	h := newHarness(t, nil)
	for _, bad := range []string{"../../x", "abc", ""} {
		if code := Run(h.ctx, []string{"marker", "clear", bad}); code != 2 {
			t.Errorf("%q exit %d", bad, code)
		}
	}
}

func TestMarkerShowPrintsMarker(t *testing.T) {
	h := newHarness(t, nil)
	_ = config.WriteMarker(h.ctx.ConfigDir, cmdSession, config.Marker{ThreadID: 42, TeleportID: "tp", Owner: "yak"})
	if code := Run(h.ctx, []string{"marker", "show", cmdSession}); code != 0 || !strings.Contains(h.out.String(), `"thread_id": 42`) {
		t.Fatalf("%d %s", code, h.out)
	}
}

func TestMarkerClearRemovesBlock(t *testing.T) {
	h := newHarness(t, nil)
	_ = config.WriteMarker(h.ctx.ConfigDir, cmdSession, config.Marker{ThreadID: 42, TeleportID: "tp", Owner: "yak"})
	deps := hook.HookDeps{ConfigDir: h.ctx.ConfigDir}
	in := hook.Input{SessionID: cmdSession}
	if !strings.Contains(hook.UserPromptSubmit(deps, in), `"block"`) {
		t.Fatal("expected a block first")
	}
	if code := Run(h.ctx, []string{"marker", "clear", cmdSession}); code != 0 {
		t.Fatal(code, h.errb)
	}
	if out := hook.UserPromptSubmit(deps, in); out != "" {
		t.Fatal(out)
	}
}
