package main

import (
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
	mu          sync.Mutex
	credentials map[string]Credential
	lookups     int
}

func (f *fakeSource) Lookup(_ context.Context, placeholder string) (Credential, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	f.lookups++
	credential, ok := f.credentials[placeholder]
	if !ok {
		return Credential{}, errors.New("unknown placeholder")
	}
	return credential, nil
}

func mustURL(text string) *url.URL {
	parsed, err := url.Parse(text)
	if err != nil {
		panic(err)
	}
	return parsed
}

type upstreamRequest struct {
	method, path, authorization, apiKey, beta, forwardedFor string
	body                                                    string
}

// proxyHarness runs the proxy in front of a fake Anthropic API and returns a client that connects from a chosen source address.
type proxyHarness struct {
	proxy    *AuthProxy
	source   *fakeSource
	requests chan upstreamRequest
	front    *httptest.Server
	status   int
}

func newProxyHarness(t *testing.T, credentials map[string]Credential, containers map[string]string) *proxyHarness {
	t.Helper()
	h := &proxyHarness{source: &fakeSource{credentials: credentials}, requests: make(chan upstreamRequest, 10), status: http.StatusOK}
	upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		body, _ := io.ReadAll(r.Body)
		h.requests <- upstreamRequest{r.Method, r.URL.Path, r.Header.Get("Authorization"), r.Header.Get("X-Api-Key"), r.Header.Get("Anthropic-Beta"), r.Header.Get("X-Forwarded-For"), string(body)}
		w.Header().Set("Retry-After", "17")
		w.Header().Set("Anthropic-Ratelimit-Unified-Status", "rejected")
		w.WriteHeader(h.status)
		_, _ = w.Write([]byte("event: ping\n\n"))
		if flusher, ok := w.(http.Flusher); ok {
			flusher.Flush()
		}
	}))
	t.Cleanup(upstream.Close)
	proxy, err := NewAuthProxy(h.source, fakeAddresses(containers), mustURL(upstream.URL), nil, slog.New(slog.NewTextHandler(io.Discard, nil)))
	if err != nil {
		t.Fatal(err)
	}
	h.proxy = proxy
	h.front = httptest.NewServer(proxy)
	t.Cleanup(h.front.Close)
	return h
}

// do sends a request as if it came from the given source address.
func (h *proxyHarness) do(t *testing.T, method, path, authorization, source string) *http.Response {
	t.Helper()
	recorder := httptest.NewRecorder()
	request := httptest.NewRequest(method, path, strings.NewReader(`{"model":"m"}`))
	request.RemoteAddr = source + ":40000"
	if authorization != "" {
		request.Header.Set("Authorization", authorization)
	}
	request.Header.Set("X-Api-Key", "sandbox-supplied")
	request.Header.Set("Anthropic-Beta", "oauth-2025-04-20,claude-code-20250219")
	h.proxy.ServeHTTP(recorder, request)
	return recorder.Result()
}

func (h *proxyHarness) upstreamRequest(t *testing.T) upstreamRequest {
	t.Helper()
	select {
	case request := <-h.requests:
		return request
	case <-time.After(2 * time.Second):
		t.Fatal("nothing reached the upstream")
		return upstreamRequest{}
	}
}

func (h *proxyHarness) assertNothingForwarded(t *testing.T) {
	t.Helper()
	select {
	case request := <-h.requests:
		t.Fatalf("a request was forwarded: %+v", request)
	case <-time.After(50 * time.Millisecond):
	}
}

func oauthCredential() map[string]Credential {
	return map[string]Credential{"yakph-1": {Type: "oauth_token", Value: "real-token", Container: "c1"}}
}

func TestProxySwapsThePlaceholderForTheRealToken(t *testing.T) {
	h := newProxyHarness(t, oauthCredential(), map[string]string{"c1": "10.100.0.5"})

	response := h.do(t, http.MethodPost, "/v1/messages?beta=true", "Bearer yakph-1", "10.100.0.5")

	if response.StatusCode != http.StatusOK {
		t.Fatalf("status = %d", response.StatusCode)
	}
	forwarded := h.upstreamRequest(t)
	if forwarded.authorization != "Bearer real-token" || forwarded.apiKey != "" || forwarded.forwardedFor != "" {
		t.Fatalf("forwarded request = %+v", forwarded)
	}
	if forwarded.beta != "oauth-2025-04-20,claude-code-20250219" {
		t.Fatalf("beta header changed for an oauth account: %q", forwarded.beta)
	}
}

func TestProxyUsesAnApiKeyHeaderForAnApiKeyAccount(t *testing.T) {
	h := newProxyHarness(t, map[string]Credential{"yakph-1": {Type: "api_key", Value: "sk-ant-api", Container: "c1"}}, map[string]string{"c1": "10.100.0.5"})

	h.do(t, http.MethodPost, "/v1/messages", "Bearer yakph-1", "10.100.0.5")

	forwarded := h.upstreamRequest(t)
	if forwarded.apiKey != "sk-ant-api" || forwarded.authorization != "" || forwarded.beta != "claude-code-20250219" {
		t.Fatalf("forwarded request = %+v", forwarded)
	}
}

func TestProxyRelaysStatusAndHeadersVerbatim(t *testing.T) {
	h := newProxyHarness(t, oauthCredential(), map[string]string{"c1": "10.100.0.5"})
	h.status = http.StatusTooManyRequests

	response := h.do(t, http.MethodPost, "/v1/messages", "Bearer yakph-1", "10.100.0.5")

	if response.StatusCode != http.StatusTooManyRequests || response.Header.Get("Retry-After") != "17" || response.Header.Get("Anthropic-Ratelimit-Unified-Status") != "rejected" {
		t.Fatalf("response = %d %v", response.StatusCode, response.Header)
	}
}

func TestProxyRefusesAnotherContainersAddress(t *testing.T) {
	h := newProxyHarness(t, oauthCredential(), map[string]string{"c1": "10.100.0.5"})

	response := h.do(t, http.MethodPost, "/v1/messages", "Bearer yakph-1", "10.100.0.9")

	if response.StatusCode != http.StatusForbidden {
		t.Fatalf("status = %d", response.StatusCode)
	}
	h.assertNothingForwarded(t)
}

func TestProxyRefusesUnknownAndMissingPlaceholders(t *testing.T) {
	h := newProxyHarness(t, oauthCredential(), map[string]string{"c1": "10.100.0.5"})

	for _, authorization := range []string{"Bearer yakph-unknown", "Bearer sk-ant-real-looking", ""} {
		if status := h.do(t, http.MethodPost, "/v1/messages", authorization, "10.100.0.5").StatusCode; status != http.StatusUnauthorized {
			t.Fatalf("%q: status = %d", authorization, status)
		}
	}
	h.assertNothingForwarded(t)
}

func TestProxyAllowsOnlyTheCLIsPaths(t *testing.T) {
	h := newProxyHarness(t, oauthCredential(), map[string]string{"c1": "10.100.0.5"})

	for _, path := range []string{"/v1/models", "/v1/organizations/x", "/api/oauth/profile", "/v1/messagesx", "/"} {
		if status := h.do(t, http.MethodPost, path, "Bearer yakph-1", "10.100.0.5").StatusCode; status != http.StatusNotFound {
			t.Fatalf("%s: status = %d", path, status)
		}
	}
	if status := h.do(t, http.MethodPost, "/v1/messages/count_tokens", "Bearer yakph-1", "10.100.0.5").StatusCode; status != http.StatusOK {
		t.Fatalf("count_tokens status = %d", status)
	}
	h.upstreamRequest(t)
}

func TestProxyForwardsTheStartupProbeWithoutACredential(t *testing.T) {
	h := newProxyHarness(t, oauthCredential(), map[string]string{"c1": "10.100.0.5"})

	h.do(t, http.MethodHead, "/api/hello", "", "10.100.0.5")

	forwarded := h.upstreamRequest(t)
	if forwarded.method != http.MethodHead || forwarded.authorization != "" || forwarded.apiKey != "" {
		t.Fatalf("forwarded request = %+v", forwarded)
	}
}

func TestProxyBindsValidationPlaceholdersToTheHost(t *testing.T) {
	h := newProxyHarness(t, map[string]Credential{"yakph-v": {Type: "oauth_token", Value: "pasted", Host: true}}, nil)

	if status := h.do(t, http.MethodPost, "/v1/messages", "Bearer yakph-v", "10.100.0.5").StatusCode; status != http.StatusForbidden {
		t.Fatalf("sandbox address status = %d", status)
	}
	if status := h.do(t, http.MethodPost, "/v1/messages", "Bearer yakph-v", "127.0.0.1").StatusCode; status != http.StatusOK {
		t.Fatalf("host address status = %d", status)
	}
	if forwarded := h.upstreamRequest(t); forwarded.authorization != "Bearer pasted" {
		t.Fatalf("forwarded request = %+v", forwarded)
	}
}

func TestProxyCachesTheCredentialAndForgetsItOnRevoke(t *testing.T) {
	h := newProxyHarness(t, oauthCredential(), map[string]string{"c1": "10.100.0.5"})

	h.do(t, http.MethodPost, "/v1/messages", "Bearer yakph-1", "10.100.0.5")
	h.do(t, http.MethodPost, "/v1/messages", "Bearer yakph-1", "10.100.0.5")
	if h.source.lookups != 1 {
		t.Fatalf("lookups = %d, want 1", h.source.lookups)
	}

	h.proxy.Revoke("yakph-1")
	delete(h.source.credentials, "yakph-1")

	if status := h.do(t, http.MethodPost, "/v1/messages", "Bearer yakph-1", "10.100.0.5").StatusCode; status != http.StatusUnauthorized {
		t.Fatalf("status after revoke = %d", status)
	}
}

func TestProxyReadsARotatedSecretAfterTheUpstreamRejectsTheOldOne(t *testing.T) {
	h := newProxyHarness(t, oauthCredential(), map[string]string{"c1": "10.100.0.5"})
	h.status = http.StatusUnauthorized
	h.do(t, http.MethodPost, "/v1/messages", "Bearer yakph-1", "10.100.0.5")
	h.upstreamRequest(t)

	h.source.credentials["yakph-1"] = Credential{Type: "oauth_token", Value: "rotated", Container: "c1"}
	h.status = http.StatusOK
	h.do(t, http.MethodPost, "/v1/messages", "Bearer yakph-1", "10.100.0.5")

	if forwarded := h.upstreamRequest(t); forwarded.authorization != "Bearer rotated" {
		t.Fatalf("forwarded request = %+v", forwarded)
	}
}

func TestProxyNeverLogsOrReturnsTheRealToken(t *testing.T) {
	h := newProxyHarness(t, oauthCredential(), map[string]string{"c1": "10.100.0.5"})

	response := h.do(t, http.MethodPost, "/v1/messages", "Bearer yakph-1", "10.100.0.5")

	body, _ := io.ReadAll(response.Body)
	if strings.Contains(string(body), "real-token") || strings.Contains(response.Header.Get("Authorization"), "real-token") {
		t.Fatal("the real token reached the response")
	}
}
