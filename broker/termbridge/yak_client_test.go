package termbridge

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func tokenFile(t *testing.T) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), "token")
	os.WriteFile(path, []byte("secret-bearer\n"), 0o600)

	return path
}

func TestRedeemSendsBodyAndBearerAndParsesGrant(t *testing.T) {
	var seen map[string]string
	var auth string
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		auth = r.Header.Get("Authorization")
		json.NewDecoder(r.Body).Decode(&seen)
		w.Write([]byte(`{"thread_id":7,"address":"127.16.0.7","container":"c-7","ttl":900}`))
	}))
	defer server.Close()

	client := &YakClient{BaseURL: server.URL, TokenFile: tokenFile(t)}
	grant, err := client.Redeem(context.Background(), "tok", "ssh-ed25519 AAAA key", "https://yak.test")
	if err != nil {
		t.Fatal(err)
	}

	if auth != "Bearer secret-bearer" || seen["token"] != "tok" || seen["public_key"] != "ssh-ed25519 AAAA key" || seen["origin"] != "https://yak.test" {
		t.Fatalf("request %q %v", auth, seen)
	}
	if grant.ThreadID != 7 || grant.Address != "127.16.0.7" || grant.Container != "c-7" || grant.TTL != 15*time.Minute {
		t.Fatalf("grant %+v", grant)
	}
}

func TestRedeemMapsNon200ToErrDeniedWithoutLeaking(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(403)
		w.Write([]byte("leaky body tok-123"))
	}))
	defer server.Close()

	client := &YakClient{BaseURL: server.URL, TokenFile: tokenFile(t)}
	_, err := client.Redeem(context.Background(), "tok-123", "ssh-ed25519 PUBKEY", "https://yak.test")
	if !errors.Is(err, ErrDenied) {
		t.Fatalf("err %v", err)
	}
	if strings.Contains(err.Error(), "tok-123") || strings.Contains(err.Error(), "leaky") {
		t.Fatalf("error leaks: %v", err)
	}
}

func TestRedeemTransportErrorDoesNotLeakTokenOrKey(t *testing.T) {
	client := &YakClient{BaseURL: "http://127.0.0.1:1", TokenFile: tokenFile(t)}
	_, err := client.Redeem(context.Background(), "tok-123", "ssh-ed25519 PUBKEY", "o")
	if err == nil || strings.Contains(err.Error(), "tok-123") || strings.Contains(err.Error(), "PUBKEY") || strings.Contains(err.Error(), "secret-bearer") {
		t.Fatalf("err %v", err)
	}
}

func TestEphemeralKeyIsGeneratedAndCleanedUp(t *testing.T) {
	private, public, cleanup, err := NewEphemeralKey(t.TempDir())
	if err != nil {
		t.Fatal(err)
	}
	info, err := os.Stat(filepath.Dir(private))
	if err != nil || info.Mode().Perm() != 0o700 {
		t.Fatalf("dir %v %v", info, err)
	}
	if !strings.HasPrefix(public, "ssh-ed25519 ") {
		t.Fatalf("public %q", public)
	}

	cleanup()
	cleanup()
	if _, err := os.Stat(filepath.Dir(private)); !os.IsNotExist(err) {
		t.Fatalf("dir still there: %v", err)
	}
}

func TestEphemeralKeyCleanupRunsAfterPanic(t *testing.T) {
	var directory string
	func() {
		defer func() { recover() }()
		private, _, cleanup, err := NewEphemeralKey(t.TempDir())
		if err != nil {
			t.Fatal(err)
		}
		directory = filepath.Dir(private)
		defer cleanup()
		panic("boom")
	}()

	if _, err := os.Stat(directory); !os.IsNotExist(err) {
		t.Fatalf("dir survived panic: %v", err)
	}
}
