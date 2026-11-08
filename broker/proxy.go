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
	// credentialCacheTTL bounds how long a looked-up credential, and the sandbox address it is bound to,
	// is reused. A rotated secret or a revoked placeholder takes effect within this time.
	credentialCacheTTL = 30 * time.Second
)

// AddressResolver returns the IPv4 address of a sandbox container.
type AddressResolver interface {
	ContainerAddress(ctx context.Context, container string) (string, error)
}

type binding struct {
	credential Credential
	address    string
	expires    time.Time
}

type contextKey struct{}

// AuthProxy forwards a sandbox's Anthropic requests with the real credential
// swapped in for the thread's placeholder. A request is accepted only for a
// placeholder the Yak app knows, from the address of the container the
// placeholder is bound to, and only for the paths the CLI needs.
type AuthProxy struct {
	source    CredentialSource
	addresses AddressResolver
	hostNets  []*net.IPNet
	logger    *slog.Logger
	reverse   *httputil.ReverseProxy
	upstream  *url.URL
	now       func() time.Time

	mu    sync.Mutex
	cache map[string]*binding
}

// NewAuthProxy builds the proxy. hostCIDRs are the networks the Yak app itself calls from;
// loopback is always included.
func NewAuthProxy(source CredentialSource, addresses AddressResolver, upstream *url.URL, hostCIDRs []string, logger *slog.Logger) (*AuthProxy, error) {
	proxy := &AuthProxy{source: source, addresses: addresses, logger: logger, now: time.Now, cache: map[string]*binding{}}
	for _, cidr := range append([]string{"127.0.0.0/8", "::1/128"}, hostCIDRs...) {
		_, network, err := net.ParseCIDR(cidr)
		if err != nil {
			return nil, err
		}
		proxy.hostNets = append(proxy.hostNets, network)
	}
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.DisableCompression = true
	proxy.reverse = &httputil.ReverseProxy{
		Director:       func(*http.Request) {},
		Transport:      transport,
		FlushInterval:  -1,
		ModifyResponse: proxy.afterResponse,
		ErrorHandler: func(w http.ResponseWriter, r *http.Request, err error) {
			logger.Warn("upstream request failed", "error", err)
			deny(w, http.StatusBadGateway, "upstream request failed")
		},
	}
	proxy.upstream = upstream
	return proxy, nil
}

func (p *AuthProxy) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	route, ok := matchRoute(r)
	if !ok {
		deny(w, http.StatusNotFound, "path not allowed")
		return
	}
	if route == "HEAD /api/hello" {
		p.forward(w, r, nil, "", "/api/hello")
		return
	}
	placeholder := bearerToken(r)
	if !strings.HasPrefix(placeholder, placeholderPrefix) {
		deny(w, http.StatusUnauthorized, "unknown placeholder")
		return
	}
	source, _, _ := net.SplitHostPort(r.RemoteAddr)
	credential, status, reason := p.authorize(r.Context(), placeholder, source)
	if credential == nil {
		deny(w, status, reason)
		return
	}
	p.forward(w, r, credential, placeholder, strings.TrimPrefix(route, "POST "))
}

// authorize returns the credential for a placeholder used from the given source address.
func (p *AuthProxy) authorize(ctx context.Context, placeholder, source string) (*Credential, int, string) {
	entry, status, reason := p.binding(ctx, placeholder)
	if entry == nil {
		return nil, status, reason
	}
	allowed := entry.address == source
	if entry.credential.Host {
		allowed = p.isHost(source)
	}
	if !allowed {
		p.logger.Warn("placeholder used from the wrong address", "source", source)
		return nil, http.StatusForbidden, "placeholder not valid from this address"
	}
	return &entry.credential, 0, ""
}

func (p *AuthProxy) isHost(source string) bool {
	address := net.ParseIP(source)
	for _, network := range p.hostNets {
		if address != nil && network.Contains(address) {
			return true
		}
	}
	return false
}

func (p *AuthProxy) binding(ctx context.Context, placeholder string) (*binding, int, string) {
	p.mu.Lock()
	entry := p.cache[placeholder]
	p.mu.Unlock()
	if entry != nil && p.now().Before(entry.expires) {
		return entry, 0, ""
	}
	credential, err := p.source.Lookup(ctx, placeholder)
	if err != nil {
		p.Revoke(placeholder)
		p.logger.Warn("credential lookup failed", "error", err)
		return nil, http.StatusUnauthorized, "unknown placeholder"
	}
	entry = &binding{credential: credential, expires: p.now().Add(credentialCacheTTL)}
	if !credential.Host {
		address, err := p.addresses.ContainerAddress(ctx, credential.Container)
		if err != nil || address == "" {
			p.logger.Warn("container address lookup failed", "container", credential.Container, "error", err)
			return nil, http.StatusBadGateway, "container address unknown"
		}
		entry.address = address
	}
	p.mu.Lock()
	p.cache[placeholder] = entry
	p.mu.Unlock()
	return entry, 0, ""
}

// Revoke forgets a placeholder, so the next request asks the Yak app again.
func (p *AuthProxy) Revoke(placeholder string) {
	if p == nil || placeholder == "" {
		return
	}
	p.mu.Lock()
	delete(p.cache, placeholder)
	p.mu.Unlock()
}

type forwarded struct {
	placeholder string
}

func (p *AuthProxy) forward(w http.ResponseWriter, r *http.Request, credential *Credential, placeholder, path string) {
	outgoing := r.Clone(context.WithValue(r.Context(), contextKey{}, forwarded{placeholder: placeholder}))
	outgoing.URL.Scheme = p.upstream.Scheme
	outgoing.URL.Host = p.upstream.Host
	outgoing.URL.Path = path
	outgoing.URL.RawPath = ""
	outgoing.Host = p.upstream.Host
	outgoing.RequestURI = ""
	outgoing.Header.Del("Authorization")
	outgoing.Header.Del("X-Api-Key")
	// A nil entry stops ReverseProxy from adding the sandbox's address.
	outgoing.Header["X-Forwarded-For"] = nil
	if credential != nil {
		if credential.Type == "api_key" {
			outgoing.Header.Set("X-Api-Key", credential.Value)
			outgoing.Header.Set("Anthropic-Beta", withoutOAuthBeta(outgoing.Header.Get("Anthropic-Beta")))
			if outgoing.Header.Get("Anthropic-Beta") == "" {
				outgoing.Header.Del("Anthropic-Beta")
			}
		} else {
			outgoing.Header.Set("Authorization", "Bearer "+credential.Value)
		}
	}
	p.reverse.ServeHTTP(w, outgoing)
}

// afterResponse forgets a credential the API rejected, so a rotated secret is read on the next request.
func (p *AuthProxy) afterResponse(response *http.Response) error {
	if response.StatusCode == http.StatusUnauthorized {
		if info, ok := response.Request.Context().Value(contextKey{}).(forwarded); ok {
			p.Revoke(info.placeholder)
		}
	}
	return nil
}

// allowedRoutes are the only method and path pairs the CLI needs.
var allowedRoutes = map[string]bool{
	"POST /v1/messages":              true,
	"POST /v1/messages/count_tokens": true,
	"HEAD /api/hello":                true,
}

// matchRoute returns the allowlist key for a request, or false when the method and path are not
// allowed or the path is not already in canonical form.
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

func bearerToken(r *http.Request) string {
	if value, ok := strings.CutPrefix(r.Header.Get("Authorization"), "Bearer "); ok {
		return strings.TrimSpace(value)
	}
	return strings.TrimSpace(r.Header.Get("X-Api-Key"))
}

// withoutOAuthBeta drops the OAuth beta flag, which an API key request must not carry.
func withoutOAuthBeta(header string) string {
	kept := []string{}
	for _, flag := range strings.Split(header, ",") {
		if flag = strings.TrimSpace(flag); flag != "" && !strings.HasPrefix(flag, "oauth-") {
			kept = append(kept, flag)
		}
	}
	return strings.Join(kept, ",")
}

func deny(w http.ResponseWriter, status int, message string) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	body, _ := json.Marshal(map[string]any{"type": "error", "error": map[string]string{"type": "permission_error", "message": message}})
	_, _ = w.Write(body)
}
