package cmd

import (
	"crypto/sha256"
	"encoding/hex"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/geocodio/yak/cli/internal/api"
	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/fakeyak"
	"github.com/geocodio/yak/cli/internal/gitx/testrepo"
	"github.com/geocodio/yak/cli/internal/ui"
)

// scriptedChoice answers Choose with a fixed index and records the question and options.
type scriptedChoice struct {
	failingPrompter
	index    int
	question string
	options  []ui.Option
}

func (p *scriptedChoice) Choose(q string, options []ui.Option, _ int) (int, error) {
	p.question, p.options = q, options
	return p.index, nil
}

func homeWithProfiles(t *testing.T, names ...string) string {
	t.Helper()
	home := t.TempDir()
	for _, name := range names {
		if err := os.MkdirAll(filepath.Join(home, name, "projects"), 0o755); err != nil {
			t.Fatal(err)
		}
	}
	return home
}

func TestAskClaudeProfileSavesTheAnswer(t *testing.T) {
	h := newHarness(t, nil)
	home := homeWithProfiles(t, ".claude", ".claude-personal", ".claude-work")
	_ = os.Mkdir(filepath.Join(home, ".claude-empty"), 0o755)
	h.ctx.Env = func(string) string { return "" }
	prompt := &scriptedChoice{failingPrompter: failingPrompter{t}, index: 2}

	cfg, err := askClaudeProfile(h.ctx, prompt, config.Config{}, home)
	if err != nil || cfg.ClaudeConfigDir != filepath.Join(home, ".claude-work") {
		t.Fatalf("%+v %v", cfg, err)
	}
	if !strings.Contains(prompt.question, "Which Claude Code profile") || len(prompt.options) != 3 {
		t.Fatalf("%q %v", prompt.question, prompt.options)
	}
	if saved, _ := config.Load(h.ctx.ConfigDir); saved.ClaudeConfigDir != cfg.ClaudeConfigDir {
		t.Fatalf("not saved: %+v", saved)
	}
}

func TestAskClaudeProfileStaysQuietWhenTheChoiceIsMade(t *testing.T) {
	h := newHarness(t, nil)
	several := homeWithProfiles(t, ".claude", ".claude-work")
	quiet := failingPrompter{t}
	none := func(string) string { return "" }

	h.ctx.Env = func(k string) string {
		if k == "CLAUDE_CONFIG_DIR" {
			return "/somewhere"
		}
		return ""
	}
	if _, err := askClaudeProfile(h.ctx, quiet, config.Config{}, several); err != nil {
		t.Fatal(err)
	}
	h.ctx.Env = none
	if _, err := askClaudeProfile(h.ctx, quiet, config.Config{ClaudeConfigDir: "~/.claude-work"}, several); err != nil {
		t.Fatal(err)
	}
	if _, err := askClaudeProfile(h.ctx, quiet, config.Config{}, homeWithProfiles(t, ".claude")); err != nil {
		t.Fatal(err)
	}
}

func seedDownThread(t *testing.T, r *teleportRig) {
	t.Helper()
	yak := testrepo.Clone(t, r.origin)
	yak.Git("switch", "-q", "-c", "yak/fix")
	tip := yak.Commit(map[string]string{"yak.txt": "x\n"}, "yak work")
	yak.Git("push", "-q", "origin", "yak/fix")
	r.srv.Threads[42] = &fakeyak.Thread{ID: 42, Title: "Fix", Repo: "acme/widgets", Branch: "yak/fix", OwnerSide: "yak", Status: "open"}
	var manifest api.DownManifest
	manifest.Thread.ID, manifest.Thread.Slug = 42, "fix"
	manifest.Repos = []api.ManifestRepo{{Repo: "acme/widgets", Role: "primary", CloneURL: r.origin, Branch: "yak/fix", HeadSHA: tip}}
	manifest.Session.SessionID = cmdSession
	body := "{\"type\":\"user\",\"message\":{\"role\":\"user\",\"content\":\"Fix it\"}}\n{\"yak\":1}\n"
	sum := sha256.Sum256([]byte(body))
	manifest.Session.Files = []api.SessionFile{{RelPath: cmdSession + ".jsonl", Mode: "append", Length: int64(len(body)), SHA256: hex.EncodeToString(sum[:])}}
	r.srv.SeedSessionFile(42, cmdSession, cmdSession+".jsonl", []byte(body))
	r.srv.ScriptDownStates(42, manifest, "ready")
}

func TestTeleportDownUsesTheSavedProfileAndLaunchesClaudeInIt(t *testing.T) {
	r := newTeleportRig(t)
	seedDownThread(t, r)
	home := t.TempDir()
	work := filepath.Join(home, ".claude-work")
	_ = os.MkdirAll(work, 0o755)
	r.ctx.Env = func(k string) string {
		if k == "HOME" {
			return home
		}
		return ""
	}
	if err := (config.Config{BaseURL: r.srv.URL(), DeviceName: "work mac", ClaudeConfigDir: "~/.claude-work"}).Save(r.ctx.ConfigDir); err != nil {
		t.Fatal(err)
	}
	r.ctx.Interactive = true
	r.ctx.Prompter = &scriptedConfirm{failingPrompter: failingPrompter{t}, answer: true}
	started := false
	r.ctx.Exec = func(string, []string) error { started = true; return nil }

	if code := Run(r.ctx, []string{"teleport", "42"}); code != 0 || !started {
		t.Fatalf("exit %d started %v: %s%s", code, started, r.out, r.errb)
	}
	if got := os.Getenv("CLAUDE_CONFIG_DIR"); got != work {
		t.Fatalf("CLAUDE_CONFIG_DIR %q", got)
	}
	if _, err := os.Stat(filepath.Join(work, "projects")); err != nil {
		t.Fatal("session not written to the saved profile")
	}
	if m, _ := config.ReadMarker(r.ctx.ConfigDir, cmdSession); m.LaunchPID != os.Getpid() {
		t.Fatalf("launch pid %d", m.LaunchPID)
	}
}

func TestTeleportDownPrintsTheProfileInTheCommand(t *testing.T) {
	r := newTeleportRig(t)
	seedDownThread(t, r)
	home := t.TempDir()
	profile := filepath.Join(home, ".claude-work")
	_ = os.MkdirAll(profile, 0o755)
	r.ctx.Env = func(k string) string {
		switch k {
		case "HOME":
			return home
		case "CLAUDE_CONFIG_DIR":
			return profile
		}
		return ""
	}
	r.ctx.Exec = nil
	if code := Run(r.ctx, []string{"teleport", "42"}); code != 0 {
		t.Fatalf("exit %d: %s%s", code, r.out, r.errb)
	}
	if !strings.Contains(r.out.String(), "CLAUDE_CONFIG_DIR="+profile+" YAK_TELEPORT_EPOCH=") {
		t.Fatalf("command lacks the profile:\n%s", r.out)
	}
	if m, _ := config.ReadMarker(r.ctx.ConfigDir, cmdSession); m.LaunchPID != 0 {
		t.Fatalf("launch pid set without starting claude: %d", m.LaunchPID)
	}
}
