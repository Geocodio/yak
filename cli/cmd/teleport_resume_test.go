package cmd

import (
	"crypto/sha256"
	"encoding/hex"
	"os"
	"strings"
	"testing"

	"github.com/geocodio/yak/cli/internal/api"
	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/fakeyak"
	"github.com/geocodio/yak/cli/internal/gitx/testrepo"
)

// scriptedConfirm answers every question with its fixed answer and records the questions.
type scriptedConfirm struct {
	failingPrompter
	answer    bool
	questions []string
}

func (p *scriptedConfirm) Confirm(q string, _ bool) (bool, error) {
	p.questions = append(p.questions, q)
	return p.answer, nil
}

func TestTeleportDownPausesBeforeClaudeClearsTheScreenAndPassesTheEpoch(t *testing.T) {
	r := newTeleportRig(t)
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

	prompt := &scriptedConfirm{failingPrompter: failingPrompter{t}, answer: true}
	r.ctx.Prompter, r.ctx.Interactive = prompt, true
	started := false
	r.ctx.Exec = func(string, []string) error { started = true; return nil }
	t.Setenv("YAK_TELEPORT_EPOCH", "")
	if code := Run(r.ctx, []string{"teleport", "42"}); code != 0 {
		t.Fatalf("exit %d: %s%s", code, r.out, r.errb)
	}
	if !started || len(prompt.questions) != 1 || !strings.Contains(prompt.questions[0], "Start claude") {
		t.Fatalf("started %v, questions %v", started, prompt.questions)
	}
	m, ok := config.ReadMarker(r.ctx.ConfigDir, cmdSession)
	if !ok || m.ResumeEpoch == "" || os.Getenv("YAK_TELEPORT_EPOCH") != m.ResumeEpoch {
		t.Fatalf("epoch env %q, marker %+v", os.Getenv("YAK_TELEPORT_EPOCH"), m)
	}
	if !strings.Contains(r.out.String(), "Thread 42 is now on this computer.") {
		t.Fatal(r.out.String())
	}
}

func TestTeleportDownDeclinedStartPrintsTheCommandWithTheEpoch(t *testing.T) {
	r := newTeleportRig(t)
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

	r.ctx.Prompter, r.ctx.Interactive = &scriptedConfirm{failingPrompter: failingPrompter{t}}, true
	started := false
	r.ctx.Exec = func(string, []string) error { started = true; return nil }
	if code := Run(r.ctx, []string{"teleport", "42"}); code != 0 || started {
		t.Fatalf("exit %d started %v: %s%s", code, started, r.out, r.errb)
	}
	if !strings.Contains(r.out.String(), "YAK_TELEPORT_EPOCH=") {
		t.Fatalf("manual command lacks the epoch:\n%s", r.out)
	}
}
