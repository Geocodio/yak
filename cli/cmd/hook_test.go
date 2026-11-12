package cmd

import (
	"strings"
	"testing"
)

func TestHookBadStdinExitsZeroSilently(t *testing.T) {
	h := newHarness(t, nil)
	for _, name := range []string{"session-start", "user-prompt-submit"} {
		h.ctx.Stdin = strings.NewReader("not json")
		if code := Run(h.ctx, []string{"hook", name}); code != 0 || h.out.Len() != 0 {
			t.Fatalf("%s: exit %d out %q", name, code, h.out)
		}
	}
}
