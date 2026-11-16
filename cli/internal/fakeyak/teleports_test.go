package fakeyak

import (
	"context"
	"encoding/base64"
	"testing"
	"time"

	"github.com/geocodio/yak/cli/internal/api"
	"github.com/geocodio/yak/cli/internal/gitx/testrepo"
)

const sid = "4f1c0c9e-7b53-4a52-9f7d-1c2b3a4d5e6f"

type teleFixture struct {
	s      *Server
	c      *api.Client
	device api.TokenResponse
	bare   string
}

func newTeleFixture(t *testing.T) *teleFixture {
	t.Helper()
	s := New(t)
	bare := s.AddRepoWithOrigin(t, "acme/widgets")
	dev := s.AddDevice("work mac")
	return &teleFixture{s: s, c: client(s, dev.Token), device: dev, bare: bare}
}

func (f *teleFixture) beginUp(t *testing.T, branch string, onDefault bool, head string) (api.BeginUpResponse, error) {
	t.Helper()
	var out api.BeginUpResponse
	err := f.c.Do(context.Background(), "POST", "/teleports", api.BeginUpRequest{
		Direction: "up", SessionID: sid, TitleHint: "Fix the geocode cache", LocalPath: "/x", OS: "darwin", Arch: "arm64",
		Repos: []api.BeginRepo{{Repo: "acme/widgets", Role: "primary", LocalPath: "/x", Branch: &branch, OnDefaultBranch: onDefault, HeadSHA: &head}},
	}, &out)
	return out, err
}

func (f *teleFixture) putSession(t *testing.T, tp api.BeginUpResponse, content string) {
	t.Helper()
	ctx := context.Background()
	rel := sid + ".jsonl"
	var out map[string]any
	if err := f.c.Do(ctx, "POST", "/threads/"+itoa(tp.Thread.ID)+"/session/append", map[string]any{
		"session_id": sid, "rel_path": rel, "offset": 0, "chunk": base64.StdEncoding.EncodeToString([]byte(content)),
		"chunk_sha256": hexSum([]byte(content)), "tail_sha256": hexSum(nil),
	}, &out); err != nil {
		t.Fatal(err)
	}
	if err := f.c.Do(ctx, "POST", "/threads/"+itoa(tp.Thread.ID)+"/session/checkpoint", map[string]any{
		"session_id": sid, "rel_path": rel, "sha256": hexSum([]byte(content)),
	}, &out); err != nil {
		t.Fatal(err)
	}
}

func itoa(n int) string { return string(appendInt(nil, n)) }

func appendInt(b []byte, n int) []byte {
	if n >= 10 {
		b = appendInt(b, n/10)
	}
	return append(b, byte('0'+n%10))
}

func pushBranch(t *testing.T, bare, branch string) string {
	t.Helper()
	r := testrepo.Clone(t, bare)
	r.Git("switch", "-c", branch)
	sha := r.Commit(map[string]string{"a.txt": "a"}, "work")
	r.Git("push", "-q", "origin", branch)
	return sha
}

func TestBeginUpCreatesThreadAndAssignsYakBranchOnDefault(t *testing.T) {
	f := newTeleFixture(t)
	out, err := f.beginUp(t, "main", true, "abc")
	if err != nil {
		t.Fatal(err)
	}
	if !out.Thread.IsNew || out.Thread.Slug != "fix-the-geocode-cache" || out.TeleportID == "" {
		t.Fatalf("%+v", out)
	}
	if len(out.Repos) != 1 || out.Repos[0].Branch != "yak/fix-the-geocode-cache" || !out.Repos[0].CreateBranch {
		t.Fatalf("%+v", out.Repos)
	}
	th := f.s.Threads[out.Thread.ID]
	if th.OwnerSide != "device" || th.OwnerDevice.ID != f.device.Device.ID {
		t.Fatalf("%+v", th)
	}
}

func TestBeginUpIdempotent(t *testing.T) {
	f := newTeleFixture(t)
	first, _ := f.beginUp(t, "feat", false, "abc")
	second, err := f.beginUp(t, "feat", false, "abc")
	if err != nil || !second.Resumed || second.TeleportID != first.TeleportID {
		t.Fatalf("%+v %v", second, err)
	}
	if first.Repos[0].CreateBranch || first.Repos[0].Branch != "feat" {
		t.Fatalf("%+v", first.Repos)
	}
}

func TestCompleteUpRequiresPushedHead(t *testing.T) {
	f := newTeleFixture(t)
	ctx := context.Background()
	tp, _ := f.beginUp(t, "feat", false, "abc")
	f.putSession(t, tp, "{}\n")
	sha := pushBranch(t, f.bare, "feat")
	path := "/teleports/" + tp.TeleportID + "/complete"
	bad := api.CompleteUpRequest{Repos: []api.CompleteUpRepo{{Repo: "acme/widgets", Branch: "nope", HeadSHA: sha}}}
	if err := f.c.Do(ctx, "POST", path, bad, nil); !api.IsCode(err, "push_not_visible") {
		t.Fatalf("want push_not_visible, got %v", err)
	}
	var done api.CompleteUpResponse
	good := api.CompleteUpRequest{Repos: []api.CompleteUpRepo{{Repo: "acme/widgets", Branch: "feat", HeadSHA: sha}}, Message: "go"}
	if err := f.c.Do(ctx, "POST", path, good, &done); err != nil {
		t.Fatal(err)
	}
	if done.Ownership != "yak" || done.Marker.Owner != "yak" || !done.TurnStarted || done.Teleport.State != "completed" {
		t.Fatalf("%+v", done)
	}
	if f.s.Threads[tp.Thread.ID].OwnerSide != "yak" {
		t.Fatal("ownership did not move")
	}
}

func TestCancelAfterCompleteIs409(t *testing.T) {
	f := newTeleFixture(t)
	ctx := context.Background()
	tp, _ := f.beginUp(t, "feat", false, "abc")
	f.putSession(t, tp, "{}\n")
	sha := pushBranch(t, f.bare, "feat")
	_ = f.c.Do(ctx, "POST", "/teleports/"+tp.TeleportID+"/complete", api.CompleteUpRequest{Repos: []api.CompleteUpRepo{{Repo: "acme/widgets", Branch: "feat", HeadSHA: sha}}}, nil)
	err := f.c.Do(ctx, "POST", "/teleports/"+tp.TeleportID+"/cancel", api.CancelRequest{Reason: "error"}, nil)
	if !api.IsCode(err, "teleport_completed") {
		t.Fatal(err)
	}
}

func TestDownManifestShapeMatchesContract(t *testing.T) {
	f := newTeleFixture(t)
	ctx := context.Background()
	f.s.Threads[42] = &Thread{ID: 42, Repo: "acme/widgets", OwnerSide: "yak", Visibility: "private"}
	var manifest api.DownManifest
	manifest.Thread.ID, manifest.Thread.Slug = 42, "fix"
	manifest.Repos = []api.ManifestRepo{{Repo: "acme/widgets", Role: "primary", Branch: "yak/fix", HeadSHA: "def"}}
	manifest.Session.SessionID = sid
	f.s.ScriptDownStates(42, manifest, "preparing", "ready")
	var begin api.BeginDownResponse
	if err := f.c.Do(ctx, "POST", "/teleports", api.BeginDownRequest{Direction: "down", ThreadID: 42}, &begin); err != nil {
		t.Fatal(err)
	}
	var poll1 api.Teleport
	if err := f.c.Do(ctx, "GET", "/teleports/"+begin.TeleportID, nil, &poll1); err != nil || poll1.State != "preparing" {
		t.Fatalf("%+v %v", poll1, err)
	}
	var ready api.DownManifest
	if err := f.c.Do(ctx, "GET", "/teleports/"+begin.TeleportID, nil, &ready); err != nil {
		t.Fatal(err)
	}
	if ready.Teleport.State != "ready" || ready.Repos[0].HeadSHA != "def" || ready.Session.SessionID != sid {
		t.Fatalf("%+v", ready)
	}
	if err := f.c.Do(ctx, "POST", "/teleports", api.BeginDownRequest{Direction: "down", ThreadID: 42}, &begin); err != nil || !begin.Resumed {
		t.Fatalf("%+v %v", begin, err)
	}
}

func TestSessionWritesRequireOpenUpTeleport(t *testing.T) {
	f := newTeleFixture(t)
	ctx := context.Background()
	f.s.Threads[42] = &Thread{ID: 42, Repo: "acme/widgets", OwnerSide: "yak"}
	err := f.c.Do(ctx, "POST", "/threads/42/session/checkpoint", map[string]any{"session_id": sid, "rel_path": sid + ".jsonl", "sha256": "x"}, nil)
	if !api.IsCode(err, "no_open_teleport") {
		t.Fatal(err)
	}
	tp, _ := f.beginUp(t, "feat", false, "abc")
	f.putSession(t, tp, "{}\n")
	f.s.Clock = func() time.Time { return time.Now().Add(time.Hour) }
	err = f.c.Do(ctx, "POST", "/teleports/"+tp.TeleportID+"/cancel", api.CancelRequest{Reason: "error"}, nil)
	if err != nil {
		t.Fatal(err)
	}
	if got, _ := f.s.Teleport(tp.TeleportID); got.State != "expired" {
		t.Fatalf("want expired, got %s", got.State)
	}
}
