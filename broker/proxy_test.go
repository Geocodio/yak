package main

import (
	"bytes"
	"context"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"net/url"
	"strings"
	"sync"
	"testing"
	"time"
)

type fakeSource struct {
	mu      sync.Mutex
	entries map[string]Credential
	lookups []string
	failure error
}

func (f *fakeSource) Lookup(_ context.Context, placeholder, ip string) (Credential, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.lookups = append(f.lookups, placeholder+"|"+ip)
	if f.failure != nil {
		return Credential{}, f.failure
	}
	if credential, ok := f.entries[placeholder+"|"+ip]; ok {
		return credential, nil
	}
	return Credential{}, ErrUnknownPlaceholder
}

func (f *fakeSource) lookupCount() int {
	f.mu.Lock()
	defer f.mu.Unlock()
	return len(f.lookups)
}

type upstreamRequest struct {
	Method, Path, Authorization, APIKey, Beta, ForwardedFor string
	Header                                                  http.Header
	Body                                                    []byte
}

type proxyHarness struct {
	t        *testing.T
	source   *fakeSource
	proxy    *AuthProxy
	upstream *httptest.Server
	clock    time.Time
	logs     *bytes.Buffer

	mu       sync.Mutex
	received []upstreamRequest
	respond  http.HandlerFunc
}

func newProxyHarness(t *testing.T, entries map[string]Credential) *proxyHarness {
	t.Helper()
	h := &proxyHarness{t: t, source: &fakeSource{entries: entries}, clock: time.Unix(1_000_000, 0), logs: &bytes.Buffer{}}
	h.upstream = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		h.mu.Lock()
		h.received = append(h.received, upstreamRequest{
			Method: r.Method, Path: r.URL.Path, Authorization: r.Header.Get("Authorization"), APIKey: r.Header.Get("X-Api-Key"),
			Beta: r.Header.Get("Anthropic-Beta"), ForwardedFor: r.Header.Get("X-Forwarded-For"), Header: r.Header.Clone(), Body: body,
		})
		respond := h.respond
		h.mu.Unlock()
		if respond != nil {
			respond(w, r)
			return
		}
		w.WriteHeader(http.StatusOK)
	}))
	t.Cleanup(h.upstream.Close)
	target, _ := url.Parse(h.upstream.URL)
	logger := slog.New(slog.NewTextHandler(h.logs, &slog.HandlerOptions{Level: slog.LevelDebug}))
	h.proxy = NewAuthProxy(h.source, target, logger)
	h.proxy.now = func() time.Time { return h.clock }
	return h
}

func (h *proxyHarness) forwarded() []upstreamRequest {
	h.mu.Lock()
	defer h.mu.Unlock()
	return append([]upstreamRequest(nil), h.received...)
}

func (h *proxyHarness) do(method, path, authorization, source string) *httptest.ResponseRecorder {
	request := httptest.NewRequest(method, path, strings.NewReader(`{"model":"x"}`))
	request.RemoteAddr = source + ":40000"
	if authorization != "" {
		request.Header.Set("Authorization", authorization)
	}
	recorder := httptest.NewRecorder()
	h.proxy.ServeHTTP(recorder, request)
	return recorder
}

var validCredentials = map[string]Credential{"yakph-a|10.0.0.5": {Kind: "setup_token", Secret: "real-token", AccountID: 1}}

func TestProxyRefusesMissingAndUnknownPlaceholders(t *testing.T) {
	h := newProxyHarness(t, validCredentials)
	for _, authorization := range []string{"", "Bearer sk-ant-real", "Bearer yakph-x"} {
		response := h.do("POST", "/v1/messages", authorization, "10.0.0.5")
		if response.Code != http.StatusUnauthorized {
			t.Fatalf("%q: status %d", authorization, response.Code)
		}
		if !strings.Contains(response.Body.String(), `"type":"permission_error"`) || !strings.Contains(response.Body.String(), `"type":"error"`) {
			t.Fatalf("body %s", response.Body)
		}
	}
	if len(h.forwarded()) != 0 {
		t.Fatal("request reached the upstream")
	}
}

func TestProxyPassesSourceIPToTheCredentialSource(t *testing.T) {
	h := newProxyHarness(t, validCredentials)
	h.do("POST", "/v1/messages", "Bearer yakph-a", "10.0.0.5")
	if len(h.source.lookups) != 1 || h.source.lookups[0] != "yakph-a|10.0.0.5" {
		t.Fatalf("lookups %v", h.source.lookups)
	}
}

func TestProxyRefusesAnotherContainersAddress(t *testing.T) {
	h := newProxyHarness(t, validCredentials)
	if response := h.do("POST", "/v1/messages", "Bearer yakph-a", "10.0.0.6"); response.Code != http.StatusUnauthorized {
		t.Fatalf("status %d", response.Code)
	}
	if len(h.forwarded()) != 0 {
		t.Fatal("request reached the upstream")
	}
}

func TestProxyIgnoresSpoofedForwardingHeaders(t *testing.T) {
	h := newProxyHarness(t, validCredentials)
	request := httptest.NewRequest("POST", "/v1/messages", nil)
	request.RemoteAddr = "10.0.0.6:40000"
	request.Header.Set("Authorization", "Bearer yakph-a")
	request.Header.Set("X-Forwarded-For", "10.0.0.5")
	request.Header.Set("Forwarded", "for=10.0.0.5")
	recorder := httptest.NewRecorder()
	h.proxy.ServeHTTP(recorder, request)
	if recorder.Code != http.StatusUnauthorized {
		t.Fatalf("status %d", recorder.Code)
	}
	if len(h.source.lookups) != 1 || h.source.lookups[0] != "yakph-a|10.0.0.6" {
		t.Fatalf("lookups %v", h.source.lookups)
	}
}

func TestProxyCachesPerPlaceholderAndAddress(t *testing.T) {
	h := newProxyHarness(t, validCredentials)
	h.do("POST", "/v1/messages", "Bearer yakph-a", "10.0.0.5")
	h.do("POST", "/v1/messages", "Bearer yakph-a", "10.0.0.5")
	if h.source.lookupCount() != 1 {
		t.Fatalf("lookups %d", h.source.lookupCount())
	}
	h.clock = h.clock.Add(credentialCacheTTL + time.Second)
	h.do("POST", "/v1/messages", "Bearer yakph-a", "10.0.0.5")
	if h.source.lookupCount() != 2 {
		t.Fatalf("lookups %d", h.source.lookupCount())
	}
}

func TestProxyRevokeForcesRelookup(t *testing.T) {
	h := newProxyHarness(t, validCredentials)
	h.do("POST", "/v1/messages", "Bearer yakph-a", "10.0.0.5")
	h.proxy.Revoke("yakph-a")
	h.do("POST", "/v1/messages", "Bearer yakph-a", "10.0.0.5")
	if h.source.lookupCount() != 2 {
		t.Fatalf("lookups %d", h.source.lookupCount())
	}
	var nilProxy *AuthProxy
	nilProxy.Revoke("x")
}

func TestProxyAnswers503WhenTheCredentialServiceFails(t *testing.T) {
	h := newProxyHarness(t, validCredentials)
	h.source.failure = errors.New("dial unix /run/yak/secret.sock: boom")
	response := h.do("POST", "/v1/messages", "Bearer yakph-a", "10.0.0.5")
	if response.Code != http.StatusServiceUnavailable {
		t.Fatalf("status %d", response.Code)
	}
	if strings.Contains(response.Body.String(), "sock") || strings.Contains(response.Body.String(), "boom") {
		t.Fatalf("body leaks: %s", response.Body)
	}
}
