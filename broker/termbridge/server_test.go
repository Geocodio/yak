package main

import (
	"context"
	"net/http/httptest"
	"os"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

type stubRedeemer struct {
	calls     atomic.Int32
	err       error
	threadID  int64
	publicKey atomic.Value
}

func (s *stubRedeemer) Redeem(_ context.Context, token, publicKey, origin string) (Grant, error) {
	s.calls.Add(1)
	s.publicKey.Store(publicKey)
	if s.err != nil {
		return Grant{}, s.err
	}

	return Grant{ThreadID: s.threadID, Address: "127.16.0.1", TTL: 15 * time.Minute}, nil
}

func testServer(t *testing.T, redeemer *stubRedeemer, mutate func(*Server)) (*httptest.Server, string, string) {
	t.Helper()
	bin, pidFile, _ := fakeSSH(t)
	bridge := &Server{
		Redeemer:     redeemer,
		Origins:      []string{"https://yak.test"},
		Session:      Session{SSHBin: bin},
		KeyDir:       t.TempDir(),
		MaxPerThread: 4,
		MaxTotal:     64,
	}
	if mutate != nil {
		mutate(bridge)
	}
	server := httptest.NewServer(bridge.Handler())
	t.Cleanup(server.Close)

	return server, pidFile, bin
}

func TestHealthz(t *testing.T) {
	server, _, _ := testServer(t, &stubRedeemer{}, nil)
	response, err := server.Client().Get(server.URL + "/healthz")
	if err != nil || response.StatusCode != 200 {
		t.Fatalf("%v %v", response, err)
	}
}

func TestRejectsBeforeUpgradeOrYakCall(t *testing.T) {
	redeemer := &stubRedeemer{}
	server, _, _ := testServer(t, redeemer, nil)

	cases := []struct {
		name, path, origin string
		status             int
	}{
		{"bad origin", "/terminal/ws?token=abc", "https://evil", 403},
		{"missing origin", "/terminal/ws?token=abc", "", 403},
		{"no token", "/terminal/ws", "https://yak.test", 400},
		{"long token", "/terminal/ws?token=" + strings.Repeat("a", 129), "https://yak.test", 400},
	}
	for _, c := range cases {
		_, response := dialPath(t, server, c.path, c.origin)
		if response.StatusCode != c.status {
			t.Errorf("%s: status %d", c.name, response.StatusCode)
		}
	}
	if redeemer.calls.Load() != 0 {
		t.Fatal("yak was called")
	}
}

func TestDeniedTokenClosesWith4403AndRunsNoSSH(t *testing.T) {
	redeemer := &stubRedeemer{err: ErrDenied}
	server, pidFile, _ := testServer(t, redeemer, nil)

	client, response := dialPath(t, server, "/terminal/ws?token=abc", "https://yak.test")
	if response.StatusCode != 101 {
		t.Fatalf("status %d", response.StatusCode)
	}
	op, payload := client.read(t)
	if op != opClose || closeCode(payload) != 4403 {
		t.Fatalf("op %d payload %v", op, payload)
	}
	if _, err := os.Stat(pidFile); err == nil {
		t.Fatal("ssh ran")
	}
}

func TestFullJourney(t *testing.T) {
	redeemer := &stubRedeemer{threadID: 5}
	server, _, _ := testServer(t, redeemer, nil)

	client, _ := dialPath(t, server, "/terminal/ws?token=abc&cols=100&rows=30", "https://yak.test")
	readUntil(t, client, "ready")

	key, _ := redeemer.publicKey.Load().(string)
	if !strings.HasPrefix(key, "ssh-ed25519 ") {
		t.Fatalf("public key %q", key)
	}

	client.send(true, opBinary, []byte("size\n"), true)
	readUntil(t, client, "30 100")
	client.send(true, opBinary, []byte("hello\n"), true)
	readUntil(t, client, "got:hello")
	client.send(true, opClose, []byte{0x03, 0xE8}, true)
}

func TestPerThreadLimit(t *testing.T) {
	redeemer := &stubRedeemer{threadID: 9}
	server, _, _ := testServer(t, redeemer, func(s *Server) { s.MaxPerThread = 1 })

	first, _ := dialPath(t, server, "/terminal/ws?token=a", "https://yak.test")
	readUntil(t, first, "ready")

	second, _ := dialPath(t, server, "/terminal/ws?token=b", "https://yak.test")
	for {
		op, payload := second.read(t)
		if op == opClose {
			if closeCode(payload) != 4429 {
				t.Fatalf("closed %d", closeCode(payload))
			}
			break
		}
	}
}

func TestIdleTimeoutThroughServer(t *testing.T) {
	server, _, _ := testServer(t, &stubRedeemer{threadID: 1}, func(s *Server) { s.Session.IdleTimeout = 300 * time.Millisecond })

	client, _ := dialPath(t, server, "/terminal/ws?token=a", "https://yak.test")
	for {
		op, payload := client.read(t)
		if op == opClose {
			if closeCode(payload) != 4408 {
				t.Fatalf("closed %d", closeCode(payload))
			}
			return
		}
	}
}

func TestAbsoluteLifetimeEndsSession(t *testing.T) {
	server, _, _ := testServer(t, &stubRedeemer{threadID: 1}, func(s *Server) { s.MaxDuration = 300 * time.Millisecond })

	client, _ := dialPath(t, server, "/terminal/ws?token=a", "https://yak.test")
	for {
		op, payload := client.read(t)
		if op == opClose {
			if closeCode(payload) != 1001 {
				t.Fatalf("closed %d", closeCode(payload))
			}
			return
		}
	}
}

func TestOverallLimitClosesWith4429(t *testing.T) {
	redeemer := &stubRedeemer{threadID: 1}
	server, _, _ := testServer(t, redeemer, func(s *Server) { s.MaxTotal = 0 })

	client, _ := dialPath(t, server, "/terminal/ws?token=a", "https://yak.test")
	if op, payload := client.read(t); op != opClose || closeCode(payload) != 4429 || redeemer.calls.Load() != 0 {
		t.Fatalf("op %d payload %v", op, payload)
	}
}
