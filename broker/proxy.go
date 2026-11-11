package main

import (
	"encoding/json"
	"log/slog"
	"net"
	"net/http"
	"net/url"
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

	mu    sync.Mutex
	cache map[cacheKey]cacheEntry
}

func NewAuthProxy(source CredentialSource, upstream *url.URL, logger *slog.Logger) *AuthProxy {
	return &AuthProxy{source: source, upstream: upstream, logger: logger, now: time.Now, cache: map[cacheKey]cacheEntry{}}
}

func (p *AuthProxy) ServeHTTP(w http.ResponseWriter, r *http.Request) {
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
	p.forward(w, r, credential, placeholder, ip)
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

// forward is implemented with the pass-through in the next step.
func (p *AuthProxy) forward(w http.ResponseWriter, r *http.Request, credential Credential, placeholder, ip string) {
	w.WriteHeader(http.StatusNoContent)
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
