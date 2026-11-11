package cmd

import (
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"

	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/fakeyak"
	"github.com/geocodio/yak/cli/internal/gitx/testrepo"
	"github.com/geocodio/yak/cli/internal/ui"
)

type openedTerminal struct {
	dir  string
	argv []string
}

func linkRig(t *testing.T) (*harness, *fakeyak.Server, *[]openedTerminal, string) {
	t.Helper()
	for _, kv := range testrepo.Env {
		k, v, _ := strings.Cut(kv, "=")
		t.Setenv(k, v)
	}
	srv := fakeyak.New(t)
	h := newHarness(t, srv)
	tok := srv.AddDevice("work mac")
	_ = h.ctx.Keychain.Set(srv.URL(), tok.Token)
	_ = (config.Config{BaseURL: srv.URL(), DeviceName: "work mac"}).Save(h.ctx.ConfigDir)
	srv.Threads[42] = &fakeyak.Thread{ID: 42, Repo: "acme/widgets", OwnerSide: "yak"}
	home := t.TempDir()
	h.ctx.Env = func(k string) string {
		if k == "HOME" {
			return home
		}
		return ""
	}
	h.ctx.Executable = func() (string, error) { return "/opt/yak/bin/yak", nil }
	var opened []openedTerminal
	h.ctx.OpenTerminal = func(dir string, argv []string) error { opened = append(opened, openedTerminal{dir, argv}); return nil }
	return h, srv, &opened, home
}

func TestOpenLinkAsksCheckoutOnceThenRemembers(t *testing.T) {
	h, _, opened, home := linkRig(t)
	checkout := filepath.Join(home, "projects", "widgets")
	_ = os.MkdirAll(checkout, 0o755)
	for _, args := range [][]string{{"init", "-q"}, {"remote", "add", "origin", "git@github.com:acme/widgets.git"}} {
		if out, err := exec.Command("git", append([]string{"-C", checkout}, args...)...).CombinedOutput(); err != nil {
			t.Fatalf("%v: %s", err, out)
		}
	}

	if code := Run(h.ctx, []string{"open-link", "yak://teleport/42"}); code != 0 {
		t.Fatalf("exit %d: %s", code, h.errb)
	}
	if len(*opened) != 1 || (*opened)[0].dir != checkout || strings.Join((*opened)[0].argv, " ") != "/opt/yak/bin/yak teleport --from-link 42" {
		t.Fatalf("%+v", *opened)
	}
	cfg, _ := config.Load(h.ctx.ConfigDir)
	if cfg.Checkouts["acme/widgets"] != checkout {
		t.Fatalf("%+v", cfg.Checkouts)
	}
	// Second time: no prompt, even with a prompter that would fail.
	h.ctx.Prompter = failingPrompter{t}
	if code := Run(h.ctx, []string{"open-link", "yak://teleport/42"}); code != 0 || len(*opened) != 2 {
		t.Fatalf("exit %d opened %d", code, len(*opened))
	}
}

type failingPrompter struct{ t *testing.T }

func (p failingPrompter) Confirm(string, bool) (bool, error) {
	p.t.Fatal("prompted")
	return false, nil
}
func (p failingPrompter) Choose(string, []ui.Option, int) (int, error) {
	p.t.Fatal("prompted")
	return 0, nil
}
func (p failingPrompter) Pick(string, []ui.PickItem) ([]int, error) {
	p.t.Fatal("prompted")
	return nil, nil
}
func (p failingPrompter) Edit(string, string) (string, error) { p.t.Fatal("prompted"); return "", nil }

func TestOpenLinkNeverRunsRawURL(t *testing.T) {
	h, srv, opened, _ := linkRig(t)
	for _, raw := range []string{"yak://teleport/42;touch /tmp/x", "yak://teleport/42/../1", "http://evil"} {
		if code := Run(h.ctx, []string{"open-link", raw}); code != 1 {
			t.Fatalf("%q exit %d", raw, code)
		}
	}
	if len(*opened) != 0 || len(srv.Requests()) != 0 {
		t.Fatal("acted on a hostile link")
	}
}

func TestOpenLinkPrintsAbsoluteBinaryCommand(t *testing.T) {
	h, _, _, home := linkRig(t)
	dir := filepath.Join(home, "w")
	_ = os.MkdirAll(dir, 0o755)
	cfg, _ := config.Load(h.ctx.ConfigDir)
	cfg.Checkouts = map[string]string{"acme/widgets": dir}
	_ = cfg.Save(h.ctx.ConfigDir)
	h.ctx.OpenTerminal = nil
	if code := Run(h.ctx, []string{"open-link", "yak://teleport/42"}); code != 0 {
		t.Fatal(code)
	}
	want := "Run: cd '" + dir + "' && '/opt/yak/bin/yak' 'teleport' '--from-link' '42'\n"
	if got := h.out.String(); got != want {
		t.Fatalf("got %q want %q", got, want)
	}
}
