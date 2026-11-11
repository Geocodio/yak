package cmd

import (
	"os"
	"strings"
	"testing"

	"github.com/geocodio/yak/cli/internal/fakeyak"
)

const tooOldCopy = "This version of yak is too old for Yak."

func TestTooOldStopsEveryCommandWithCopy(t *testing.T) {
	cases := map[string][]string{
		"login":         {"login", "--url", "SERVER", "--name", "box"},
		"teleport up":   {"teleport", "--session", cmdSession},
		"teleport down": {"teleport", "42"},
		"whoami":        {"whoami"},
		"logout":        {"logout"},
		"ssh":           {"ssh", "42"},
	}
	for name, args := range cases {
		t.Run(name, func(t *testing.T) {
			r := newTeleportRig(t)
			r.srv.Threads[42] = &fakeyak.Thread{ID: 42, Repo: "acme/widgets", OwnerSide: "yak"}
			r.srv.MinProtocol = 2
			for i, a := range args {
				if a == "SERVER" {
					args[i] = r.srv.URL()
				}
			}
			code := Run(r.ctx, args)
			e := r.errb.String()
			if code != 1 || !strings.Contains(e, tooOldCopy) ||
				!strings.Contains(e, "Nothing was changed.") || !strings.Contains(e, "claude plugin update yak") {
				t.Fatalf("exit %d: %s", code, e)
			}
		})
	}
}

func TestTooOldTouchesNothingLocally(t *testing.T) {
	r := newTeleportRig(t)
	r.local.Git("switch", "-q", "-c", "feat")
	r.srv.MinProtocol = 2
	before := r.local.Git("for-each-ref")
	code := Run(r.ctx, []string{"teleport", "--session", cmdSession})
	if code != 1 {
		t.Fatalf("exit %d", code)
	}
	for _, req := range r.srv.Requests() {
		if req.Path != "/api/cli/v1/me" {
			t.Fatalf("unexpected request %s %s", req.Method, req.Path)
		}
	}
	entries, _ := os.ReadDir(r.ctx.ConfigDir)
	if len(entries) != 1 || entries[0].Name() != "config.json" {
		t.Fatalf("state dir changed: %v", entries)
	}
	if r.local.Git("for-each-ref") != before || r.local.Git("status", "--porcelain") != "" {
		t.Fatal("repo changed")
	}
}
