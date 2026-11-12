package teleport

import (
	"bytes"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/geocodio/yak/cli/internal/api"
	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/gitx/testrepo"
	"github.com/geocodio/yak/cli/internal/hook"
)

const otherSession = "9a8b7c6d-1111-4222-8333-444455556666"

// bigTranscript is firstLine followed by enough lines to pass 3 MiB.
func bigTranscript() string {
	var b strings.Builder
	b.WriteString(firstLine)
	pad := strings.Repeat("x", 900)
	for i := 0; b.Len() < 3<<20; i++ {
		b.WriteString(`{"type":"assistant","message":{"role":"assistant","content":"` + pad + `"}}` + "\n")
	}
	return b.String()
}

func (h *harness) writeProjectFile(rel, content string) {
	h.t.Helper()
	path := filepath.Join(h.projectDir(h.cwd), rel)
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		h.t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		h.t.Fatal(err)
	}
}

func (h *harness) hookDeps(out *bytes.Buffer) hook.HookDeps {
	return hook.HookDeps{
		ConfigDir: h.deps.ConfigDir, Out: out,
		API: func() (*api.Client, bool) { return h.deps.API, true },
	}
}

// journeyUp teleports a session with a transcript, a subagent, a tool result and a memory file up from main.
func (h *harness) journeyUp(transcript string) UpResult {
	h.t.Helper()
	h.writeTranscript(transcript)
	h.writeProjectFile(testSession+"/subagents/agent-a.jsonl", "{\"sub\":1}\n")
	h.writeProjectFile(testSession+"/tool-results/out.txt", "tool output\n")
	h.writeProjectFile("memory/notes.md", "remember\n")
	res, err := h.up(PrepareOptions{})
	if err != nil {
		h.t.Fatal(err)
	}
	return res
}

// yakWorks adds a commit to the thread branch on origin and a longer session to the fake store, then scripts
// the manifest a teleport down would receive.
func (h *harness) yakWorks(res UpResult, uploaded string) (tip, longer string) {
	h.t.Helper()
	longer = uploaded + "{\"yak\":\"more work\"}\n"
	yak := testrepo.Clone(h.t, h.origin)
	yak.Git("switch", "-q", res.Branch)
	tip = yak.Commit(map[string]string{"yak.txt": "from yak\n"}, "yak work")
	yak.Git("push", "-q", "origin", res.Branch)

	h.srv.SeedSessionFile(res.ThreadID, testSession, testSession+".jsonl", []byte(longer))
	var m api.DownManifest
	m.Thread.ID, m.Thread.Slug, m.Thread.Title = res.ThreadID, "fix", "Fix the geocode cache"
	m.Repos = []api.ManifestRepo{{
		Repo: "acme/widgets", Role: "primary", CloneURL: h.origin, DefaultBranch: "main", Branch: res.Branch, HeadSHA: tip,
		SandboxPath: "/workspace", WIPCommitMade: true, Pushed: true,
	}}
	m.Session.SessionID = testSession
	m.Session.Files = []api.SessionFile{{RelPath: testSession + ".jsonl", Mode: "append", Length: int64(len(longer)), SHA256: sum(longer)}}
	m.Environment.SandboxOS, m.Environment.SandboxArch, m.Environment.SandboxWorkspacePath = "linux", "x86_64", "/workspace"
	m.LastUp = &api.DownLastUp{
		Branch: res.Branch, HeadSHA: h.local.Git("rev-parse", "HEAD"),
		Files: []api.SessionFile{{RelPath: testSession + ".jsonl", Length: int64(len(uploaded)), SHA256: sum(uploaded)}},
	}
	h.manifest = m
	h.srv.ScriptDownStates(res.ThreadID, m, "preparing", "ready")
	return tip, longer
}

func TestRoundTripUpThenDown(t *testing.T) {
	h := newHarness(t)
	mainBefore := h.originRef("refs/heads/main")
	uploaded := bigTranscript()
	res := h.journeyUp(uploaded)

	if h.originRef("refs/heads/"+res.Branch) == "" || h.originRef("refs/heads/main") != mainBefore {
		t.Fatal("up pushed the wrong refs")
	}
	if got := h.srv.SessionFile(res.ThreadID, testSession, testSession+".jsonl"); string(got) != uploaded {
		t.Fatalf("server transcript is %d bytes, want %d", len(got), len(uploaded))
	}
	if m, ok := config.ReadMarker(h.deps.ConfigDir, testSession); !ok || m.Owner != "yak" || m.ThreadID != res.ThreadID {
		t.Fatalf("marker %+v %v", m, ok)
	}
	var blocked bytes.Buffer
	prompt := hook.Input{SessionID: testSession, HookEventName: "UserPromptSubmit", Prompt: "keep going"}
	if out := hook.UserPromptSubmit(h.hookDeps(&blocked), prompt); !strings.Contains(out, `"decision":"block"`) {
		t.Fatalf("prompt not blocked: %q", out)
	}

	tip, longer := h.yakWorks(res, uploaded)
	h.srv.AddStackMessage(res.ThreadID, api.StackMessage{ID: 1, Kind: "message", Text: "first"})

	down, err := Down(t.Context(), h.deps, DownInput{ThreadID: res.ThreadID, Cwd: h.cwd})
	if err != nil {
		t.Fatal(err)
	}
	if h.local.Git("rev-parse", "HEAD") != tip || down.SessionID != testSession {
		t.Fatalf("not fast-forwarded: %+v", down)
	}
	got, _ := os.ReadFile(filepath.Join(h.projectDir(h.cwd), testSession+".jsonl"))
	if string(got) != longer {
		t.Fatal("session differs from the server copy")
	}
	if m, ok := config.ReadMarker(h.deps.ConfigDir, testSession); !ok || m.Owner != "device" {
		t.Fatalf("marker %+v %v", m, ok)
	}

	resume := hook.Input{SessionID: testSession, HookEventName: "SessionStart", Source: "resume"}
	start := hook.SessionStart(h.hookDeps(&bytes.Buffer{}), resume)
	if !strings.Contains(start, "additionalContext") || !strings.Contains(start, "Message from Yak") {
		t.Fatalf("environment note or stack message missing: %q", start)
	}
	if out := hook.SessionStart(h.hookDeps(&bytes.Buffer{}), resume); out != "" {
		t.Fatalf("note or message printed twice: %q", out)
	}
	if out := hook.UserPromptSubmit(h.hookDeps(&bytes.Buffer{}), prompt); out != "" {
		t.Fatalf("seen message reprinted: %q", out)
	}

	h.srv.AddStackMessage(res.ThreadID, api.StackMessage{ID: 2, Kind: "message", Text: "second"})
	next := hook.UserPromptSubmit(h.hookDeps(&bytes.Buffer{}), prompt)
	if !strings.Contains(next, "second") || strings.Contains(next, `"decision"`) {
		t.Fatalf("new stack message missing: %q", next)
	}
	if again := hook.UserPromptSubmit(h.hookDeps(&bytes.Buffer{}), prompt); again != "" {
		t.Fatalf("stack message repeated: %q", again)
	}

	before := len(h.srv.Requests())
	other := hook.Input{SessionID: otherSession, HookEventName: "UserPromptSubmit", Prompt: "hi"}
	if out := hook.UserPromptSubmit(h.hookDeps(&bytes.Buffer{}), other); out != "" || len(h.srv.Requests()) != before {
		t.Fatalf("hook not silent for an unrelated session: %q", out)
	}
}

func TestRevokeMidFlowLeavesEverythingUnchanged(t *testing.T) {
	h := newHarness(t)
	uploaded := bigTranscript()
	h.writeTranscript(uploaded)

	// Up: the device is revoked during the upload. Nothing is completed and no marker appears.
	revoked := false
	h.srv.OnRequest = func(r *http.Request) {
		if !revoked && strings.HasSuffix(r.URL.Path, "/append") {
			revoked = true
			h.srv.RevokeDevice(h.device.Device.ID)
		}
	}
	if _, err := h.up(PrepareOptions{}); !api.IsCode(err, "device_revoked") {
		t.Fatalf("up: %v", err)
	}
	if _, ok := config.ReadMarker(h.deps.ConfigDir, testSession); ok || len(h.requests("/complete")) != 0 {
		t.Fatal("up left a marker or completed")
	}

	// Down: the same, with a fresh device and a revoke on the first download request.
	h = newHarness(t)
	res := h.journeyUp(uploaded)
	h.yakWorks(res, uploaded)
	before := h.snapshot()
	revoked = false
	h.srv.OnRequest = func(r *http.Request) {
		if !revoked && r.Method == http.MethodGet && strings.HasSuffix(r.URL.Path, "/read") {
			revoked = true
			h.srv.RevokeDevice(h.device.Device.ID)
		}
	}
	_, err := Down(t.Context(), h.deps, DownInput{ThreadID: res.ThreadID, Cwd: h.cwd})
	if err == nil {
		t.Fatal("down succeeded for a revoked device")
	}
	if h.snapshot() != before {
		t.Fatal("down changed the working tree, HEAD or stashes")
	}
	if got, _ := os.ReadFile(filepath.Join(h.projectDir(h.cwd), testSession+".jsonl")); string(got) != uploaded {
		t.Fatal("down replaced the local session")
	}
	if m, ok := config.ReadMarker(h.deps.ConfigDir, testSession); !ok || m.Owner != "yak" {
		t.Fatalf("marker %+v %v", m, ok)
	}
	if len(h.requests("/complete")) != 1 || h.srv.Threads[res.ThreadID].OwnerSide != "yak" {
		t.Fatal("down completed or moved ownership")
	}
}

func TestMultiRepoRoundTrip(t *testing.T) {
	h := newHarness(t)
	changes, changesOrigin := addYakRepo(t, h)
	changes.Write("api.txt", "edited\n")
	docsOrigin := h.srv.AddRepoWithOrigin(t, "acme/docs")
	seed := testrepo.Clone(t, docsOrigin)
	seed.Git("switch", "-q", "-c", "main")
	seed.Commit(map[string]string{"d.txt": "d\n"}, "initial")
	seed.Git("push", "-q", "-u", "origin", "main")
	docs := testrepo.Clone(t, docsOrigin)

	uploaded := bigTranscript()
	h.local.Git("switch", "-q", "-c", "feat")
	h.writeTranscript(uploaded)
	res, err := Up(t.Context(), h.deps, UpInput{Cwd: h.cwd, SessionID: testSession, AddDirs: []string{changes.Dir, docs.Dir}, Options: PrepareOptions{CommitTracked: true}})
	if err != nil {
		t.Fatal(err)
	}
	sent := h.srv.UpRepos(t, res.TeleportID)
	if len(sent) != 3 || sent[1].Role != "changes" || sent[2].Role != "context" {
		t.Fatalf("%+v", sent)
	}

	tip, _ := h.yakWorks(res, uploaded)
	extraHead := *sent[1].HeadSHA
	h.manifest.Repos = append(h.manifest.Repos, api.ManifestRepo{
		Repo: "acme/api", Role: "changes", CloneURL: changesOrigin, DefaultBranch: "main", Branch: *sent[1].Branch, HeadSHA: extraHead,
		SandboxPath: "/extras/api", Pushed: true,
	})
	h.manifest.ContextRepos = []string{"acme/docs"}
	h.srv.ScriptDownStates(res.ThreadID, h.manifest, "preparing", "ready")

	down, err := Down(t.Context(), h.deps, DownInput{ThreadID: res.ThreadID, Cwd: h.cwd})
	if err != nil {
		t.Fatal(err)
	}
	root, _ := downFolders(h.cwd, "fix")
	if h.local.Git("rev-parse", "HEAD") != tip {
		t.Fatal("primary not fast-forwarded")
	}
	if sha, _ := gitxRepo(filepath.Join(root, "api")).Head(); sha != extraHead {
		t.Fatalf("extra at %s, want %s", sha, extraHead)
	}
	if _, err := os.Stat(filepath.Join(root, "docs")); err == nil {
		t.Fatal("context repo was brought down")
	}
	if m, ok := config.ReadMarker(h.deps.ConfigDir, testSession); !ok || m.Owner != "device" {
		t.Fatalf("marker %+v %v", m, ok)
	}
	if len(down.Applied) != 2 || down.Applied[1].Repo != "acme/api" || !strings.Contains(h.out.String(), "Applied: acme/widgets, acme/api\n") {
		t.Fatalf("%+v\n%s", down.Applied, h.out.String())
	}
}
