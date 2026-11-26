package cmd

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/geocodio/yak/cli/internal/config"
)

func TestConfigSetShowUnsetClaudeConfigDir(t *testing.T) {
	h := newHarness(t, nil)
	home := t.TempDir()
	h.ctx.Env = func(k string) string {
		if k == "HOME" {
			return home
		}
		return ""
	}
	work := filepath.Join(home, ".claude-work")
	_ = os.Mkdir(work, 0o755)

	if code := Run(h.ctx, []string{"config", "claude-config-dir", "~/.claude-work"}); code != 0 {
		t.Fatalf("set: %d %s", code, h.errb)
	}
	if cfg, _ := config.Load(h.ctx.ConfigDir); cfg.ClaudeConfigDir != "~/.claude-work" {
		t.Fatalf("saved %q", cfg.ClaudeConfigDir)
	}
	h.out.Reset()
	if code := Run(h.ctx, []string{"config"}); code != 0 || !strings.Contains(h.out.String(), "~/.claude-work") || !strings.Contains(h.out.String(), work) {
		t.Fatalf("show: %d %s", code, h.out)
	}
	if code := Run(h.ctx, []string{"config", "claude-config-dir", "--unset"}); code != 0 {
		t.Fatalf("unset: %d %s", code, h.errb)
	}
	if cfg, _ := config.Load(h.ctx.ConfigDir); cfg.ClaudeConfigDir != "" {
		t.Fatalf("still saved %q", cfg.ClaudeConfigDir)
	}
}

func TestConfigRejectsMissingFolder(t *testing.T) {
	h := newHarness(t, nil)
	if code := Run(h.ctx, []string{"config", "claude-config-dir", filepath.Join(t.TempDir(), "nope")}); code != 1 {
		t.Fatalf("exit %d", code)
	}
	if cfg, _ := config.Load(h.ctx.ConfigDir); cfg.ClaudeConfigDir != "" {
		t.Fatal("saved a missing folder")
	}
}

func TestConfigUsage(t *testing.T) {
	h := newHarness(t, nil)
	if code := Run(h.ctx, []string{"config", "bogus"}); code != 2 {
		t.Fatalf("exit %d", code)
	}
}
