package main

import (
	"bytes"
	"context"
	"errors"
	"io"
	"log/slog"
	"net"
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

func TestProxyAllowsOnlyTheCLIsPaths(t *testing.T) {
	h := newProxyHarness(t, validCredentials)
	cases := []struct {
		method, target string
		forwarded      bool
	}{
		{"POST", "/v1/messages", true},
		{"POST", "/v1/messages/count_tokens", true},
		{"GET", "/v1/messages", false},
		{"GET", "/v1/models", true},
		{"POST", "/v1/models", false},
		{"GET", "/v1/models/claude-opus-5", false},
		{"POST", "/v1/messages/../x", false},
		{"POST", "/v1//messages", false},
		{"POST", "/v1/messages/%2e%2e/x", false},
		{"POST", "/v1%2fmessages", false},
		{"POST", "/v1/organizations", false},
	}
	for _, c := range cases {
		before := len(h.forwarded())
		request := httptest.NewRequest(c.method, "http://proxy"+c.target, nil)
		request.RemoteAddr = "10.0.0.5:40000"
		request.Header.Set("Authorization", "Bearer yakph-a")
		recorder := httptest.NewRecorder()
		h.proxy.ServeHTTP(recorder, request)
		got := len(h.forwarded()) - before
		if c.forwarded && (got != 1 || recorder.Code != 200) {
			t.Fatalf("%s %s: forwarded %d status %d", c.method, c.target, got, recorder.Code)
		}
		if !c.forwarded && (got != 0 || recorder.Code != http.StatusNotFound) {
			t.Fatalf("%s %s: forwarded %d status %d", c.method, c.target, got, recorder.Code)
		}
	}
}

func TestProxyForwardsTheStartupProbeWithoutACredential(t *testing.T) {
	h := newProxyHarness(t, validCredentials)
	response := h.do("HEAD", "/api/hello", "", "10.0.0.9")
	got := h.forwarded()
	if response.Code != 200 || len(got) != 1 || got[0].Path != "/api/hello" || got[0].Authorization != "" || got[0].APIKey != "" {
		t.Fatalf("status %d received %+v", response.Code, got)
	}
	if h.source.lookupCount() != 0 {
		t.Fatal("probe triggered a lookup")
	}
}

func sandboxRequest(h *proxyHarness, mutate func(*http.Request)) *httptest.ResponseRecorder {
	request := httptest.NewRequest("POST", "/v1/messages", strings.NewReader(`{"model":"x"}`))
	request.RemoteAddr = "10.0.0.5:40000"
	request.Header.Set("Authorization", "Bearer yakph-a")
	if mutate != nil {
		mutate(request)
	}
	recorder := httptest.NewRecorder()
	h.proxy.ServeHTTP(recorder, request)
	return recorder
}

func TestProxySetupTokenUsesBearer(t *testing.T) {
	h := newProxyHarness(t, validCredentials)
	sandboxRequest(h, func(r *http.Request) {
		r.Header.Set("X-Api-Key", "sandbox-supplied")
		r.Header.Set("Anthropic-Beta", "oauth-2025-04-20,fine-grained-tool-streaming")
	})
	got := h.forwarded()[0]
	if got.Authorization != "Bearer real-token" || got.APIKey != "" || got.Beta != "oauth-2025-04-20,fine-grained-tool-streaming" || string(got.Body) != `{"model":"x"}` {
		t.Fatalf("%+v", got)
	}
}

func TestProxyApiKeyUsesXApiKey(t *testing.T) {
	h := newProxyHarness(t, map[string]Credential{"yakph-a|10.0.0.5": {Kind: "api_key", Secret: "real-key"}})
	sandboxRequest(h, func(r *http.Request) { r.Header.Set("Anthropic-Beta", "oauth-2025-04-20,context-1m") })
	sandboxRequest(h, func(r *http.Request) { r.Header.Set("Anthropic-Beta", "oauth-2025-04-20") })
	got := h.forwarded()
	if got[0].APIKey != "real-key" || got[0].Authorization != "" || got[0].Beta != "context-1m" {
		t.Fatalf("%+v", got[0])
	}
	if _, present := got[1].Header["Anthropic-Beta"]; present {
		t.Fatalf("empty beta header kept: %+v", got[1].Header)
	}
}

func TestProxyPassesRequestHeadersAndBodyByteForByte(t *testing.T) {
	h := newProxyHarness(t, validCredentials)
	body := bytes.Repeat([]byte("0123456789abcdef"), 1<<16)
	headers := map[string]string{"Anthropic-Version": "2023-06-01", "User-Agent": "claude-cli/9.9", "X-App": "cli", "Content-Type": "application/json", "Accept-Encoding": "gzip"}
	sandboxRequest(h, func(r *http.Request) {
		r.Body = io.NopCloser(bytes.NewReader(body))
		r.ContentLength = int64(len(body))
		for name, value := range headers {
			r.Header.Set(name, value)
		}
		r.Header.Set("X-Forwarded-For", "1.2.3.4")
		r.Header.Set("Forwarded", "for=1.2.3.4")
		r.Header.Set("Via", "1.1 sandbox")
	})
	got := h.forwarded()[0]
	for name, value := range headers {
		if got.Header.Get(name) != value {
			t.Fatalf("%s: %q", name, got.Header.Get(name))
		}
	}
	if !bytes.Equal(got.Body, body) {
		t.Fatal("body differs")
	}
	for _, name := range []string{"X-Forwarded-For", "Forwarded", "Via"} {
		if got.Header.Get(name) != "" {
			t.Fatalf("%s reached upstream", name)
		}
	}
}

func TestProxyRelaysResponsesVerbatim(t *testing.T) {
	h := newProxyHarness(t, validCredentials)
	gzipped := []byte{0x1f, 0x8b, 0x08, 0x00, 0xde, 0xad, 0xbe, 0xef}
	for _, status := range []int{200, 400, 404, 429, 500, 529} {
		h.mu.Lock()
		h.respond = func(w http.ResponseWriter, r *http.Request) {
			w.Header().Set("Retry-After", "17")
			w.Header().Set("Anthropic-Ratelimit-Unified-Status", "rejected")
			w.Header().Set("Request-Id", "req_1")
			w.Header().Set("Content-Encoding", "gzip")
			w.WriteHeader(status)
			_, _ = w.Write(gzipped)
		}
		h.mu.Unlock()
		response := sandboxRequest(h, nil)
		if response.Code != status || !bytes.Equal(response.Body.Bytes(), gzipped) ||
			response.Header().Get("Retry-After") != "17" || response.Header().Get("Request-Id") != "req_1" ||
			response.Header().Get("Content-Encoding") != "gzip" || response.Header().Get("Anthropic-Ratelimit-Unified-Status") != "rejected" {
			t.Fatalf("status %d: %d %v", status, response.Code, response.Header())
		}
	}
}

func TestProxyReadsARotatedSecretAfterUpstream401(t *testing.T) {
	h := newProxyHarness(t, validCredentials)
	h.respond = func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(http.StatusUnauthorized) }
	sandboxRequest(h, nil)
	sandboxRequest(h, nil)
	if h.source.lookupCount() != 2 {
		t.Fatalf("lookups %d", h.source.lookupCount())
	}
}

func TestProxyServeStopsOnContextCancel(t *testing.T) {
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	proxy := NewAuthProxy(&fakeSource{}, &url.URL{Scheme: "http", Host: "127.0.0.1:1"}, slog.New(slog.NewTextHandler(io.Discard, nil)))
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- proxy.Serve(ctx, listener) }()
	cancel()
	select {
	case err := <-done:
		if err != nil {
			t.Fatalf("Serve returned %v, want nil", err)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("Serve did not return after context cancel")
	}
}

func TestProxyServeEndToEnd(t *testing.T) {
	var seenAuthorization string
	upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		seenAuthorization = r.Header.Get("Authorization")
		w.WriteHeader(http.StatusOK)
	}))
	t.Cleanup(upstream.Close)
	socketPath := serveSocket(t, func(request string) string {
		if strings.Contains(request, `"yakph-e2e"`) && strings.Contains(request, `"127.0.0.1"`) {
			return `{"kind":"setup_token","secret":"real-token","account_id":7}`
		}
		return `{"error":"unknown"}`
	})
	target, _ := url.Parse(upstream.URL)
	proxy := NewAuthProxy(SocketCredentialSource{Path: socketPath}, target, slog.New(slog.NewTextHandler(io.Discard, nil)))
	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan error, 1)
	go func() { done <- proxy.Serve(ctx, listener) }()
	t.Cleanup(func() { cancel(); <-done })

	request, _ := http.NewRequest("POST", "http://"+listener.Addr().String()+"/v1/messages", strings.NewReader(`{}`))
	request.Header.Set("Authorization", "Bearer yakph-e2e")
	response, err := http.DefaultClient.Do(request)
	if err != nil {
		t.Fatal(err)
	}
	response.Body.Close()
	if response.StatusCode != http.StatusOK {
		t.Fatalf("status %d, want 200", response.StatusCode)
	}
	if seenAuthorization != "Bearer real-token" {
		t.Fatalf("upstream saw %q", seenAuthorization)
	}
}
