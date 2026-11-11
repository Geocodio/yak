package main

import (
	"context"
	"encoding/json"
	"log/slog"
	"net"
	"net/http"
	"net/http/httputil"
	"net/url"
	"path"
	"strings"
	"sync"
	"time"
)

const (
	placeholderPrefix = "yakph-"
	// credentialCacheTTL bounds how long a looked-up credential is reused for
	// one placeholder and sandbox address. A rotated secret or a revoked
	// placeholder takes effect within this time.
	credentialCacheTTL = 30 * time.Second
)

type cacheKey struct {
	placeholder string
	ip          string
}

type cacheEntry struct {
	credential Credential
	expires    time.Time
}

// AuthProxy forwards a sandbox's Anthropic requests with the real credential
// swapped in for the sandbox's placeholder. The sandbox address is the TCP
// peer address only; the Yak app enforces that the placeholder belongs to it.
type AuthProxy struct {
	source   CredentialSource
	upstream *url.URL
	logger   *slog.Logger
	now      func() time.Time
	reverse  *httputil.ReverseProxy

	mu    sync.Mutex
	cache map[cacheKey]cacheEntry
}

func NewAuthProxy(source CredentialSource, upstream *url.URL, logger *slog.Logger) *AuthProxy {
	proxy := &AuthProxy{source: source, upstream: upstream, logger: logger, now: time.Now, cache: map[cacheKey]cacheEntry{}}
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.DisableCompression = true
	proxy.reverse = &httputil.ReverseProxy{
		Rewrite:        proxy.rewrite,
		Transport:      transport,
		FlushInterval:  -1,
		ModifyResponse: proxy.afterResponse,
		ErrorHandler: func(w http.ResponseWriter, r *http.Request, err error) {
			logger.Warn("upstream request failed")
			deny(w, http.StatusBadGateway, "upstream request failed")
		},
	}
	return proxy
}

// allowedRoutes are the only method and path pairs the CLI needs.
var allowedRoutes = map[string]bool{
	"POST /v1/messages":              true,
	"POST /v1/messages/count_tokens": true,
	"HEAD /api/hello":                true,
}

// matchRoute returns the allowlist key for a request, or false when the method
// and path are not allowed or the path is not already in canonical form.
func matchRoute(r *http.Request) (string, bool) {
	escaped := r.URL.EscapedPath()
	lowered := strings.ToLower(escaped)
	if escaped != path.Clean(escaped) || strings.Contains(escaped, "..") || strings.Contains(escaped, "//") ||
		strings.Contains(lowered, "%2e") || strings.Contains(lowered, "%2f") {
		return "", false
	}
	key := r.Method + " " + r.URL.Path
	return key, allowedRoutes[key]
}

func (p *AuthProxy) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	route, allowed := matchRoute(r)
	if !allowed {
		deny(w, http.StatusNotFound, "path not allowed")
		return
	}
	if route == "HEAD /api/hello" {
		p.reverse.ServeHTTP(w, r.WithContext(context.WithValue(r.Context(), forwardKey{}, forwardInfo{})))
		return
	}
	placeholder := presentedPlaceholder(r)
	if !strings.HasPrefix(placeholder, placeholderPrefix) {
		deny(w, http.StatusUnauthorized, "unknown placeholder")
		return
	}
	ip, _, err := net.SplitHostPort(r.RemoteAddr)
	if err != nil {
		deny(w, http.StatusUnauthorized, "unknown placeholder")
		return
	}
	credential, status, reason := p.credentialFor(r, placeholder, ip)
	if status != 0 {
		deny(w, status, reason)
		return
	}
	p.forward(w, r, credential, placeholder)
}

func (p *AuthProxy) credentialFor(r *http.Request, placeholder, ip string) (Credential, int, string) {
	key := cacheKey{placeholder, ip}
	p.mu.Lock()
	entry, found := p.cache[key]
	p.mu.Unlock()
	if found && p.now().Before(entry.expires) {
		return entry.credential, 0, ""
	}
	credential, err := p.source.Lookup(r.Context(), placeholder, ip)
	if err != nil {
		if err == ErrUnknownPlaceholder {
			return Credential{}, http.StatusUnauthorized, "unknown placeholder"
		}
		p.logger.Warn("credential lookup failed")
		return Credential{}, http.StatusServiceUnavailable, "credential service unavailable"
	}
	now := p.now()
	p.mu.Lock()
	for existing, value := range p.cache {
		if !now.Before(value.expires) {
			delete(p.cache, existing)
		}
	}
	p.cache[key] = cacheEntry{credential, now.Add(credentialCacheTTL)}
	p.mu.Unlock()
	return credential, 0, ""
}

// Revoke forgets every cached credential for a placeholder, so the next
// request asks the Yak app again. Safe on a nil proxy.
func (p *AuthProxy) Revoke(placeholder string) {
	if p == nil || placeholder == "" {
		return
	}
	p.mu.Lock()
	defer p.mu.Unlock()
	for key := range p.cache {
		if key.placeholder == placeholder {
			delete(p.cache, key)
		}
	}
}

type forwardKey struct{}

// forwardInfo travels in the request context to Rewrite and ModifyResponse.
// A zero value means an unauthenticated startup probe.
type forwardInfo struct {
	credential  Credential
	placeholder string
}

func (p *AuthProxy) forward(w http.ResponseWriter, r *http.Request, credential Credential, placeholder string) {
	info := forwardInfo{credential, placeholder}
	p.reverse.ServeHTTP(w, r.WithContext(context.WithValue(r.Context(), forwardKey{}, info)))
}

func (p *AuthProxy) rewrite(r *httputil.ProxyRequest) {
	info, _ := r.In.Context().Value(forwardKey{}).(forwardInfo)
	out := r.Out
	out.URL.Scheme = p.upstream.Scheme
	out.URL.Host = p.upstream.Host
	out.URL.RawPath = ""
	out.Host = p.upstream.Host
	out.Header.Del("Authorization")
	out.Header.Del("X-Api-Key")
	out.Header.Del("Via")
	out.Header.Del("Forwarded")
	for name := range out.Header {
		if strings.HasPrefix(strings.ToLower(name), "x-forwarded-") {
			out.Header.Del(name)
		}
	}
	switch info.credential.Kind {
	case "api_key":
		out.Header.Set("X-Api-Key", info.credential.Secret)
		if beta := withoutOAuthBeta(out.Header.Get("Anthropic-Beta")); beta != "" {
			out.Header.Set("Anthropic-Beta", beta)
		} else {
			out.Header.Del("Anthropic-Beta")
		}
	case "setup_token":
		out.Header.Set("Authorization", "Bearer "+info.credential.Secret)
	}
}

// afterResponse forgets a credential the API rejected, so a rotated secret is
// read on the next request.
func (p *AuthProxy) afterResponse(response *http.Response) error {
	if response.StatusCode == http.StatusUnauthorized {
		if info, ok := response.Request.Context().Value(forwardKey{}).(forwardInfo); ok {
			p.Revoke(info.placeholder)
		}
	}
	return nil
}

// withoutOAuthBeta drops the OAuth beta flag, which an API key request must
// not carry.
func withoutOAuthBeta(header string) string {
	kept := []string{}
	for _, flag := range strings.Split(header, ",") {
		if flag = strings.TrimSpace(flag); flag != "" && !strings.HasPrefix(flag, "oauth-") {
			kept = append(kept, flag)
		}
	}
	return strings.Join(kept, ",")
}

func presentedPlaceholder(r *http.Request) string {
	if value, ok := strings.CutPrefix(r.Header.Get("Authorization"), "Bearer "); ok {
		return strings.TrimSpace(value)
	}
	return strings.TrimSpace(r.Header.Get("X-Api-Key"))
}

func deny(w http.ResponseWriter, status int, message string) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	body, _ := json.Marshal(map[string]any{"type": "error", "error": map[string]string{"type": "permission_error", "message": message}})
	_, _ = w.Write(body)
}
