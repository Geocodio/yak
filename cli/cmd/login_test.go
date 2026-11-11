package cmd

import (
	"bytes"
	"net/http"
	"strings"
	"testing"
	"time"

	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/fakeyak"
	"github.com/geocodio/yak/cli/internal/keychain"
	"github.com/geocodio/yak/cli/internal/ui"
)

type harness struct {
	ctx    *Context
	out    *bytes.Buffer
	errb   *bytes.Buffer
	sleeps []time.Duration
	opened []string
	now    time.Time
}

func newHarness(t *testing.T, s *fakeyak.Server) *harness {
	t.Helper()
	h := &harness{out: &bytes.Buffer{}, errb: &bytes.Buffer{}, now: time.Date(2026, 11, 13, 9, 0, 0, 0, time.UTC)}
	h.ctx = &Context{
		Stdin: strings.NewReader(""), Stdout: h.out, Stderr: h.errb,
		Env:       func(string) string { return "" },
		Keychain:  keychain.NewMemory(),
		HTTP:      http.DefaultClient,
		Now:       func() time.Time { return h.now },
		Prompter:  ui.NewNonInteractive(),
		Cwd:       t.TempDir(),
		ConfigDir: t.TempDir(),
		OpenURL:   func(u string) error { h.opened = append(h.opened, u); return nil },
	}
	h.ctx.Sleep = func(d time.Duration) { h.sleeps = append(h.sleeps, d); h.now = h.now.Add(d) }
	return h
}

func (h *harness) approveOnSleep(s *fakeyak.Server, n int) {
	prev := h.ctx.Sleep
	h.ctx.Sleep = func(d time.Duration) {
		prev(d)
		if len(h.sleeps) == n {
			s.ApproveCode(s.LastUserCode())
		}
	}
}

func TestLoginStoresTokenInKeychain(t *testing.T) {
	s := fakeyak.New(t)
	h := newHarness(t, s)
	h.approveOnSleep(s, 2)
	if code := Run(h.ctx, []string{"login", "--url", s.URL(), "--name", "Mia's MacBook Pro"}); code != 0 {
		t.Fatalf("exit %d, stderr %s", code, h.errb)
	}
	tok, err := h.ctx.Keychain.Get(s.URL())
	if err != nil || !strings.HasPrefix(tok, "yakdev_") {
		t.Fatalf("token %q %v", tok, err)
	}
	cfg, _ := config.Load(h.ctx.ConfigDir)
	if cfg.BaseURL != s.URL() || cfg.DeviceName != "Mia's MacBook Pro" {
		t.Fatalf("%+v", cfg)
	}
	out := h.out.String()
	for _, want := range []string{"Your code is WDJB-MJ", "No browser?", `Signed in as mia@acme.test. Token saved to the keychain as "Mia's MacBook Pro".`} {
		if !strings.Contains(out, want) {
			t.Fatalf("missing %q in:\n%s", want, out)
		}
	}
	if len(h.opened) != 1 || !strings.Contains(h.opened[0], "/device?code=WDJB-MJ") {
		t.Fatalf("opened %v", h.opened)
	}
}

func TestLoginSlowDownAddsFiveSeconds(t *testing.T) {
	s := fakeyak.New(t)
	h := newHarness(t, s)
	s.SlowDownNext = 1
	h.approveOnSleep(s, 3)
	if code := Run(h.ctx, []string{"login", "--url", s.URL(), "--name", "box"}); code != 0 {
		t.Fatalf("exit %d, stderr %s", code, h.errb)
	}
	want := []time.Duration{5 * time.Second, 10 * time.Second, 10 * time.Second}
	if len(h.sleeps) != 3 || h.sleeps[0] != want[0] || h.sleeps[1] != want[1] || h.sleeps[2] != want[2] {
		t.Fatalf("sleeps %v", h.sleeps)
	}
}

func TestLoginDeniedStops(t *testing.T) {
	s := fakeyak.New(t)
	h := newHarness(t, s)
	prev := h.ctx.Sleep
	h.ctx.Sleep = func(d time.Duration) { prev(d); s.DenyCode(s.LastUserCode()) }
	if code := Run(h.ctx, []string{"login", "--url", s.URL(), "--name", "box"}); code != 1 {
		t.Fatalf("exit %d", code)
	}
	if _, err := h.ctx.Keychain.Get(s.URL()); err == nil {
		t.Fatal("no token may be stored")
	}
	if !strings.Contains(h.errb.String(), "denied") {
		t.Fatal(h.errb.String())
	}
}

func TestLoginExpiredCopy(t *testing.T) {
	s := fakeyak.New(t)
	h := newHarness(t, s)
	prev := h.ctx.Sleep
	h.ctx.Sleep = func(d time.Duration) { prev(d); s.ExpireCode(s.LastUserCode()) }
	if code := Run(h.ctx, []string{"login", "--url", s.URL(), "--name", "box"}); code != 1 {
		t.Fatalf("exit %d", code)
	}
	want := "That code expired. Codes work for 10 minutes."
	if !strings.Contains(h.errb.String(), want) || !strings.Contains(h.errb.String(), "Run yak login again for a new one.") {
		t.Fatal(h.errb.String())
	}
}

func TestLoginStopsWhenWindowPasses(t *testing.T) {
	s := fakeyak.New(t)
	h := newHarness(t, s)
	if code := Run(h.ctx, []string{"login", "--url", s.URL(), "--name", "box"}); code != 1 {
		t.Fatalf("exit %d", code)
	}
	if !strings.Contains(h.errb.String(), "That code expired.") {
		t.Fatal(h.errb.String())
	}
}

func signedIn(t *testing.T, s *fakeyak.Server, h *harness) int {
	t.Helper()
	tok := s.AddDevice("box")
	_ = h.ctx.Keychain.Set(s.URL(), tok.Token)
	if err := (config.Config{BaseURL: s.URL(), DeviceName: "box"}).Save(h.ctx.ConfigDir); err != nil {
		t.Fatal(err)
	}
	return tok.Device.ID
}

func TestWhoami(t *testing.T) {
	s := fakeyak.New(t)
	h := newHarness(t, s)
	signedIn(t, s, h)
	if code := Run(h.ctx, []string{"whoami"}); code != 0 {
		t.Fatalf("exit %d %s", code, h.errb)
	}
	for _, want := range []string{"mia@acme.test", "box", s.URL()} {
		if !strings.Contains(h.out.String(), want) {
			t.Fatalf("missing %q: %s", want, h.out)
		}
	}
}

func TestWhoamiWithoutLoginSaysRunLogin(t *testing.T) {
	s := fakeyak.New(t)
	h := newHarness(t, s)
	if code := Run(h.ctx, []string{"whoami"}); code != 1 || !strings.Contains(h.errb.String(), "Run yak login.") {
		t.Fatalf("exit %d %s", code, h.errb)
	}
	if len(s.Requests()) != 0 {
		t.Fatal("no network call without a token")
	}
}

func TestLogoutDeletesTokenAndRevokes(t *testing.T) {
	s := fakeyak.New(t)
	h := newHarness(t, s)
	id := signedIn(t, s, h)
	if code := Run(h.ctx, []string{"logout"}); code != 0 {
		t.Fatalf("exit %d %s", code, h.errb)
	}
	if !s.Devices[id].Revoked {
		t.Fatal("server device must be revoked")
	}
	if _, err := h.ctx.Keychain.Get(s.URL()); err == nil {
		t.Fatal("token must be gone")
	}
}

func TestLogoutTreatsRevokedAsSuccess(t *testing.T) {
	s := fakeyak.New(t)
	h := newHarness(t, s)
	id := signedIn(t, s, h)
	s.RevokeDevice(id)
	if code := Run(h.ctx, []string{"logout"}); code != 0 {
		t.Fatalf("exit %d %s", code, h.errb)
	}
	if _, err := h.ctx.Keychain.Get(s.URL()); err == nil {
		t.Fatal("token must be gone")
	}
}

func TestRevokedMessageNamesMarkerCommand(t *testing.T) {
	s := fakeyak.New(t)
	h := newHarness(t, s)
	id := signedIn(t, s, h)
	s.RevokeDevice(id)
	if code := Run(h.ctx, []string{"whoami"}); code != 1 {
		t.Fatalf("exit %d", code)
	}
	e := h.errb.String()
	for _, want := range []string{"This computer was removed from Yak.", "Settings, Devices", "yak login", "yak marker clear <session-id>"} {
		if !strings.Contains(e, want) {
			t.Fatalf("missing %q in:\n%s", want, e)
		}
	}
	if _, err := h.ctx.Keychain.Get(s.URL()); err != nil {
		t.Fatal("a revoked message must not delete the token")
	}
}

func TestInvalidTokenSaysRunLogin(t *testing.T) {
	s := fakeyak.New(t)
	h := newHarness(t, s)
	_ = h.ctx.Keychain.Set(s.URL(), "yakdev_bogus")
	_ = (config.Config{BaseURL: s.URL()}).Save(h.ctx.ConfigDir)
	if code := Run(h.ctx, []string{"whoami"}); code != 1 || !strings.Contains(h.errb.String(), "yak login") {
		t.Fatalf("exit %d %s", code, h.errb)
	}
	if _, err := h.ctx.Keychain.Get(s.URL()); err != nil {
		t.Fatal("token must stay")
	}
}

func TestProtocolTooOldPrintsServerMessageOnly(t *testing.T) {
	s := fakeyak.New(t)
	h := newHarness(t, s)
	signedIn(t, s, h)
	s.MinProtocol = 2
	if code := Run(h.ctx, []string{"whoami"}); code != 1 || !strings.Contains(h.errb.String(), "too old") {
		t.Fatalf("exit %d %s", code, h.errb)
	}
}
