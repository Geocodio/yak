package hook

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"

	"github.com/geocodio/yak/cli/internal/api"
	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/fakeyak"
)

const sid = "4f1c0c9e-7b53-4a52-9f7d-1c2b3a4d5e6f"

type rig struct {
	dir   string
	srv   *fakeyak.Server
	deps  HookDeps
	built *int32
}

func newRig(t *testing.T) *rig {
	t.Helper()
	srv := fakeyak.New(t)
	tok := srv.AddDevice("work mac")
	var built int32
	r := &rig{dir: t.TempDir(), srv: srv, built: &built}
	r.deps = HookDeps{ConfigDir: r.dir, API: func() (*api.Client, bool) {
		atomic.AddInt32(&built, 1)
		return api.New(srv.URL(), tok.Token, http.DefaultClient, "yak-cli/test"), true
	}}
	return r
}

func (r *rig) marker(t *testing.T, owner string, thread int) {
	t.Helper()
	if err := config.WriteMarker(r.dir, sid, config.Marker{ThreadID: thread, TeleportID: "tp1", Owner: owner}); err != nil {
		t.Fatal(err)
	}
}

func (r *rig) deviceThread(id int) {
	d := r.srv.Devices
	for _, dev := range d {
		r.srv.Threads[id] = &fakeyak.Thread{ID: id, OwnerSide: "device", OwnerDevice: &api.OwnerDevice{ID: dev.ID, Name: dev.Name}}
	}
}

func msg(id int, text string) api.StackMessage {
	m := api.StackMessage{ID: id, Kind: "result", Text: text}
	m.FromThread.ID, m.FromThread.Title = 55, "Research: cache"
	return m
}

func TestNoMarkerNoOutputNoNetwork(t *testing.T) {
	r := newRig(t)
	in := Input{SessionID: sid, Source: "resume"}
	if SessionStart(r.deps, in) != "" || UserPromptSubmit(r.deps, in) != "" {
		t.Fatal("printed without a marker")
	}
	if *r.built != 0 || len(r.srv.Requests()) != 0 {
		t.Fatal("touched the network")
	}
}

func TestNoMarkerPathDoesNotConstructClient(t *testing.T) {
	d := HookDeps{ConfigDir: t.TempDir(), API: func() (*api.Client, bool) { t.Fatal("client constructed"); return nil, false }}
	UserPromptSubmit(d, Input{SessionID: sid})
	SessionStart(d, Input{SessionID: sid, Source: "resume"})
}

func TestYakOwnerBlocksWithoutNetwork(t *testing.T) {
	r := newRig(t)
	r.marker(t, "yak", 42)
	out := UserPromptSubmit(r.deps, Input{SessionID: sid})
	if !strings.Contains(out, `"decision":"block"`) || !strings.Contains(out, "This session is on Yak.") {
		t.Fatalf("got %s", out)
	}
	if *r.built != 0 {
		t.Fatal("network used")
	}
}

func TestDeviceOwnerPrintsStackMessagesAndAcks(t *testing.T) {
	r := newRig(t)
	r.marker(t, "device", 42)
	r.deviceThread(42)
	r.srv.AddStackMessage(42, msg(311, "Use redis"))
	out := UserPromptSubmit(r.deps, Input{SessionID: sid})
	if !strings.Contains(out, `"hookEventName":"UserPromptSubmit"`) || !strings.Contains(out, "Use redis") {
		t.Fatalf("got %s", out)
	}
	acked := false
	for _, q := range r.srv.Requests() {
		if q.Method == "POST" && strings.HasSuffix(q.Path, "/stack-messages/ack") && strings.Contains(string(q.Body), "311") {
			acked = true
		}
	}
	if !acked {
		t.Fatal("no ack")
	}
}

func TestRepeatedMessageIdsNotReprinted(t *testing.T) {
	r := newRig(t)
	r.marker(t, "device", 42)
	r.deviceThread(42)
	r.srv.AddStackMessage(42, msg(311, "Use redis"))
	UserPromptSubmit(r.deps, Input{SessionID: sid})
	// A crash before the ack would have left the message on the server; simulate by resetting the server side.
	r.srv.ResetAcks(42)
	if out := UserPromptSubmit(r.deps, Input{SessionID: sid}); out != "" {
		t.Fatalf("reprinted: %s", out)
	}
}

func TestNotOwner409Silent(t *testing.T) {
	r := newRig(t)
	r.marker(t, "device", 42)
	r.srv.Threads[42] = &fakeyak.Thread{ID: 42, OwnerSide: "yak"}
	if out := UserPromptSubmit(r.deps, Input{SessionID: sid}); out != "" {
		t.Fatal(out)
	}
}

func TestStatusErrorsSilent(t *testing.T) {
	for _, status := range []int{426, 401} {
		srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, _ *http.Request) {
			w.WriteHeader(status)
			_, _ = w.Write([]byte(`{"error":"x","message":"m"}`))
		}))
		defer srv.Close()
		dir := t.TempDir()
		_ = config.WriteMarker(dir, sid, config.Marker{ThreadID: 42, TeleportID: "tp", Owner: "device"})
		d := HookDeps{ConfigDir: dir, API: func() (*api.Client, bool) { return api.New(srv.URL, "t", nil, "ua"), true }}
		if out := UserPromptSubmit(d, Input{SessionID: sid}); out != "" {
			t.Fatalf("%d printed %s", status, out)
		}
	}
}

func TestServerDownSilent(t *testing.T) {
	srv := httptest.NewServer(http.NotFoundHandler())
	url := srv.URL
	srv.Close()
	dir := t.TempDir()
	_ = config.WriteMarker(dir, sid, config.Marker{ThreadID: 42, TeleportID: "tp", Owner: "device"})
	d := HookDeps{ConfigDir: dir, API: func() (*api.Client, bool) { return api.New(url, "t", nil, "ua"), true }}
	if out := UserPromptSubmit(d, Input{SessionID: sid}); out != "" {
		t.Fatal(out)
	}
}

func TestNoteOnlyOnResumeAndOnlyOnce(t *testing.T) {
	r := newRig(t)
	_ = config.WriteNote(r.dir, sid, "You are on your computer now.")
	if out := SessionStart(r.deps, Input{SessionID: sid, Source: "startup"}); out != "" {
		t.Fatal("printed on startup")
	}
	out := SessionStart(r.deps, Input{SessionID: sid, Source: "resume"})
	if !strings.Contains(out, `"hookEventName":"SessionStart"`) || !strings.Contains(out, "You are on your computer now.") {
		t.Fatalf("got %s", out)
	}
	if out := SessionStart(r.deps, Input{SessionID: sid, Source: "resume"}); out != "" {
		t.Fatal("printed twice")
	}
}

func TestBadStdinIsRejected(t *testing.T) {
	for _, s := range []string{"", "not json", "{", strings.Repeat("x", MaxInputBytes+10)} {
		if _, ok := ReadInput(strings.NewReader(s)); ok {
			t.Errorf("accepted %.10q", s)
		}
	}
}

func TestMarkerForOtherSessionIgnored(t *testing.T) {
	r := newRig(t)
	r.marker(t, "yak", 42)
	other := "11111111-7b53-4a52-9f7d-1c2b3a4d5e6f"
	if out := UserPromptSubmit(r.deps, Input{SessionID: other}); out != "" {
		t.Fatal(out)
	}
	if out := UserPromptSubmit(r.deps, Input{SessionID: "../../etc/passwd"}); out != "" {
		t.Fatal(out)
	}
}

func TestYakOwnedBlockNamesBothWaysOut(t *testing.T) {
	r := newRig(t)
	r.marker(t, "yak", 42)
	r.deps.Binary = "/opt/yak/bin/yak"
	var v struct{ Reason string }
	if err := json.Unmarshal([]byte(UserPromptSubmit(r.deps, Input{SessionID: sid})), &v); err != nil {
		t.Fatal(err)
	}
	for _, want := range []string{"'/opt/yak/bin/yak' teleport 42", "'/opt/yak/bin/yak' marker clear " + sid, "local session"} {
		if !strings.Contains(v.Reason, want) {
			t.Errorf("reason %q lacks %q", v.Reason, want)
		}
	}
}
