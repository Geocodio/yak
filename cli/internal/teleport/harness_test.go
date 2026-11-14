package teleport

import (
	"bytes"
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/geocodio/yak/cli/internal/api"
	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/fakeyak"
	"github.com/geocodio/yak/cli/internal/gitx/testrepo"
	"github.com/geocodio/yak/cli/internal/session"
	"github.com/geocodio/yak/cli/internal/ui"
)

const testSession = "4f1c0c9e-7b53-4a52-9f7d-1c2b3a4d5e6f"

const firstLine = `{"type":"user","message":{"role":"user","content":"Fix the geocode cache"},"timestamp":"2026-11-01T10:00:00Z"}` + "\n"

type harness struct {
	t         *testing.T
	srv       *fakeyak.Server
	device    api.TokenResponse
	origin    string
	local     *testrepo.Repo
	cwd       string
	claudeDir string
	out       *bytes.Buffer
	sleeps    []time.Duration
	deps      Deps
	// hostileTip is the commit pushTip last pushed.
	hostileTip string
	// manifest is what yakWorks last scripted for a teleport down.
	manifest api.DownManifest
}

func isolateGit(t *testing.T) {
	t.Helper()
	for _, kv := range testrepo.Env {
		k, v, _ := strings.Cut(kv, "=")
		t.Setenv(k, v)
	}
}

// newHarness builds a fake Yak with one repo, a local checkout on main that is pushed, and an upgraded device.
func newHarness(t *testing.T) *harness {
	t.Helper()
	isolateGit(t)
	srv := fakeyak.New(t)
	origin := srv.AddRepoWithOrigin(t, "acme/widgets")
	local := testrepo.Clone(t, origin)
	local.Git("switch", "-q", "-c", "main")
	local.Commit(map[string]string{"README.md": "hello\n"}, "initial")
	local.Git("push", "-q", "-u", "origin", "main")
	cwd, err := filepath.EvalSymlinks(local.Dir)
	if err != nil {
		t.Fatal(err)
	}
	dev := srv.AddDevice("work mac")
	h := &harness{t: t, srv: srv, device: dev, origin: origin, local: local, cwd: cwd, claudeDir: t.TempDir(), out: &bytes.Buffer{}}
	client := api.New(srv.URL(), dev.Token, nil, "yak-cli/test")
	client.Backoff = func(context.Context, int) error { return nil }
	h.deps = Deps{
		API: client, Prompter: ui.NewNonInteractive(), Out: h.out, Config: config.Config{BaseURL: srv.URL()},
		ConfigDir: t.TempDir(), Now: func() time.Time { return time.Date(2026, 11, 13, 9, 0, 0, 0, time.UTC) },
		Sleep: func(d time.Duration) { h.sleeps = append(h.sleeps, d) },
		Env: func(k string) string {
			if k == "CLAUDE_CONFIG_DIR" {
				return h.claudeDir
			}
			return ""
		},
		Home: t.TempDir(),
	}
	return h
}

func (h *harness) projectDir(cwd string) string {
	return filepath.Join(h.claudeDir, "projects", session.ProjectFolderName(cwd))
}

func (h *harness) writeTranscript(content string) {
	h.t.Helper()
	path := filepath.Join(h.projectDir(h.cwd), testSession+".jsonl")
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		h.t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		h.t.Fatal(err)
	}
}

func (h *harness) up(opts PrepareOptions) (UpResult, error) {
	return Up(context.Background(), h.deps, UpInput{Cwd: h.cwd, SessionID: testSession, Options: opts})
}

func (h *harness) requests(match string) []fakeyak.RequestLog {
	var out []fakeyak.RequestLog
	for _, r := range h.srv.Requests() {
		if strings.Contains(r.Method+" "+r.Path, match) {
			out = append(out, r)
		}
	}
	return out
}

func (h *harness) originRef(ref string) string {
	h.t.Helper()
	sha, err := gitxRepo(h.origin).Run("rev-parse", "--verify", "-q", ref)
	if err != nil {
		return ""
	}
	return sha
}
