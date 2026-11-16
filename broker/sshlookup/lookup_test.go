package main

import (
	"context"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"
)

const goodFingerprint = "SHA256:abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ"

func TestLookupReturnsLines(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer secret" || r.URL.Path != "/internal/ssh/authorized-keys" {
			http.Error(w, "no", http.StatusUnauthorized)
			return
		}
		_, _ = w.Write([]byte(`{"lines":["restrict ssh-ed25519 AAAA one","restrict ssh-ed25519 AAAA two"]}`))
	}))
	defer server.Close()

	lines, err := Lookup(context.Background(), server.URL, "secret", goodFingerprint)
	if err != nil || len(lines) != 2 || lines[0] != "restrict ssh-ed25519 AAAA one" {
		t.Fatalf("lines=%v err=%v", lines, err)
	}
}

func TestLookupBadFingerprintMakesNoRequest(t *testing.T) {
	var calls int32
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		atomic.AddInt32(&calls, 1)
	}))
	defer server.Close()

	for _, fingerprint := range []string{"", "SHA256:short", "MD5:aa:bb", goodFingerprint + "\nextra"} {
		if _, err := Lookup(context.Background(), server.URL, "secret", fingerprint); err == nil {
			t.Fatalf("expected error for %q", fingerprint)
		}
	}
	if calls != 0 {
		t.Fatalf("made %d requests", calls)
	}
}

func TestLookupNon200ReturnsError(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		http.Error(w, "boom", http.StatusInternalServerError)
	}))
	defer server.Close()

	if lines, err := Lookup(context.Background(), server.URL, "secret", goodFingerprint); err == nil || len(lines) != 0 {
		t.Fatalf("lines=%v err=%v", lines, err)
	}
}

func TestLookupTimeout(t *testing.T) {
	release := make(chan struct{})
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		<-release
	}))
	defer server.Close()
	defer close(release)

	ctx, cancel := context.WithTimeout(context.Background(), 50*time.Millisecond)
	defer cancel()

	if lines, err := Lookup(ctx, server.URL, "secret", goodFingerprint); err == nil || len(lines) != 0 {
		t.Fatalf("lines=%v err=%v", lines, err)
	}
}
