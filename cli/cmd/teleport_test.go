package cmd

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/geocodio/yak/cli/internal/api"
	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/fakeyak"
	"github.com/geocodio/yak/cli/internal/gitx/testrepo"
	"github.com/geocodio/yak/cli/internal/session"
)

const cmdSession = "4f1c0c9e-7b53-4a52-9f7d-1c2b3a4d5e6f"

type teleportRig struct {
	*harness
	srv    *fakeyak.Server
	origin string
	local  *testrepo.Repo
}

// newTeleportRig signs in, creates a Yak repo with a pushed checkout on main, and writes a session transcript.
func newTeleportRig(t *testing.T) *teleportRig {
	t.Helper()
	for _, kv := range testrepo.Env {
		k, v, _ := strings.Cut(kv, "=")
		t.Setenv(k, v)
	}
	srv := fakeyak.New(t)
	h := newHarness(t, srv)
	origin := srv.AddRepoWithOrigin(t, "acme/widgets")
	local := testrepo.Clone(t, origin)
	local.Git("switch", "-q", "-c", "main")
	local.Commit(map[string]string{"README.md": "hi\n"}, "initial")
	local.Git("push", "-q", "-u", "origin", "main")
	cwd, _ := filepath.EvalSymlinks(local.Dir)
	h.ctx.Cwd = cwd

	tok := srv.AddDevice("work mac")
	if err := h.ctx.Keychain.Set(srv.URL(), tok.Token); err != nil {
		t.Fatal(err)
	}
	if err := (config.Config{BaseURL: srv.URL(), DeviceName: "work mac"}).Save(h.ctx.ConfigDir); err != nil {
		t.Fatal(err)
	}
	claude := t.TempDir()
	h.ctx.Env = func(k string) string {
		switch k {
		case "CLAUDE_CONFIG_DIR":
			return claude
		case "HOME":
			return t.TempDir()
		}
		return ""
	}
	transcript := filepath.Join(claude, "projects", session.ProjectFolderName(cwd), cmdSession+".jsonl")
	_ = os.MkdirAll(filepath.Dir(transcript), 0o755)
	_ = os.WriteFile(transcript, []byte(`{"type":"user","message":{"role":"user","content":"Fix it"}}`+"\n"), 0o644)
	return &teleportRig{harness: h, srv: srv, origin: origin, local: local}
}

func TestTeleportNoArgRunsUp(t *testing.T) {
	r := newTeleportRig(t)
	r.local.Git("switch", "-q", "-c", "feat")
	if code := Run(r.ctx, []string{"teleport", "--session", cmdSession}); code != 0 {
		t.Fatalf("exit %d: %s%s", code, r.out, r.errb)
	}
	if !strings.Contains(r.out.String(), "Yak has the thread. This session is now read-only.") {
		t.Fatal(r.out.String())
	}
	if _, ok := config.ReadMarker(r.ctx.ConfigDir, cmdSession); !ok {
		t.Fatal("no marker")
	}
}

func TestTeleportNumericRunsDown(t *testing.T) {
	r := newTeleportRig(t)
	r.srv.Threads[42] = &fakeyak.Thread{ID: 42, Title: "Other", Repo: "acme/other", OwnerSide: "yak"}
	code := Run(r.ctx, []string{"teleport", "42", "--stash"})
	if code != exitRefused || !strings.Contains(r.errb.String(), "Thread 42 is in acme/other") {
		t.Fatalf("exit %d: %s", code, r.errb)
	}
}

func TestTeleportRejectsBadThreadArg(t *testing.T) {
	for _, bad := range []string{"42abc", "-1", "4 2", "../x", "0", "042"} {
		r := newTeleportRig(t)
		before := len(r.srv.Requests())
		code := Run(r.ctx, []string{"teleport", bad})
		if code != exitUsage {
			t.Errorf("%q: exit %d", bad, code)
		}
		if len(r.srv.Requests()) != before {
			t.Errorf("%q: made a request", bad)
		}
		if !strings.Contains(r.errb.String(), "Usage: yak teleport") {
			t.Errorf("%q: no usage:\n%s", bad, r.errb)
		}
	}
}

func TestTeleportFlagsMayFollowTheThread(t *testing.T) {
	f, positional, err := parseTeleportArgs([]string{"42", "--worktree", "--add-dir", "a", "--add-dir=b", "--keep"})
	if err != nil || len(positional) != 1 || !f.worktree || !f.keep || len(f.addDirs) != 2 {
		t.Fatalf("%+v %v %v", f, positional, err)
	}
}

func TestNonTTYDirtyRefuses(t *testing.T) {
	r := newTeleportRig(t)
	r.local.Git("switch", "-q", "-c", "feat")
	r.local.Write("README.md", "dirty\n")
	code := Run(r.ctx, []string{"teleport", "--session", cmdSession})
	if code != exitRefused || !strings.Contains(r.errb.String(), "README.md") {
		t.Fatalf("exit %d: %s", code, r.errb)
	}
	for _, req := range r.srv.Requests() {
		if req.Method == "POST" && !strings.HasSuffix(req.Path, "/repos/resolve") {
			t.Fatalf("write request %s", req.Path)
		}
	}
}

func TestExitCode3OnClash(t *testing.T) {
	r := newTeleportRig(t)
	yak := testrepo.Clone(t, r.origin)
	tip := yak.Tip([]testrepo.Entry{{Path: "A.txt", Content: "a"}, {Path: "a.txt", Content: "b"}}, "")
	yak.Git("push", "-q", "origin", tip+":refs/heads/yak/fix")
	r.srv.Threads[42] = &fakeyak.Thread{ID: 42, Title: "Fix", Repo: "acme/widgets", Branch: "yak/fix", OwnerSide: "yak", Status: "open"}
	var manifest api.DownManifest
	manifest.Thread.ID, manifest.Thread.Slug = 42, "fix"
	manifest.Repos = []api.ManifestRepo{{Repo: "acme/widgets", Role: "primary", CloneURL: r.origin, Branch: "yak/fix", HeadSHA: tip}}
	manifest.Session.SessionID = cmdSession
	r.srv.ScriptDownStates(42, manifest, "ready")
	code := Run(r.ctx, []string{"teleport", "42"})
	if code != exitRefused {
		t.Fatalf("exit %d: %s%s", code, r.out, r.errb)
	}
	if reason, _ := r.srv.CancelInfo(r.srv.LastTeleportID()); reason != "clash" {
		t.Fatalf("cancel reason %q", reason)
	}
}
