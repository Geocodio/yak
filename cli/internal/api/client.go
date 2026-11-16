// Package api is the client for /api/cli/v1.
package api

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
	"math/rand/v2"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"

	"github.com/geocodio/yak/cli/internal/version"
)

const basePath = "/api/cli/v1"

// Client talks to one Yak server as one device.
type Client struct {
	BaseURL   string
	Token     string
	HTTP      *http.Client
	UserAgent string
	// Backoff waits between retries and returns early with the context's error when ctx ends; tests replace it.
	Backoff func(ctx context.Context, attempt int) error
	// TimeoutFor sizes the per-attempt timeout from the path and the request body size; tests replace it.
	TimeoutFor func(path string, bodyBytes int) time.Duration
}

const (
	maxAttempts = 5
	// uplinkBytesPerSecond is the slowest link the timeout still waits for: a body gets its size at this rate on top of the base.
	uplinkBytesPerSecond = 16 * 1024
	baseTimeout          = 30 * time.Second
	completeTimeout      = 60 * time.Second
)

// New builds a client; token may be empty for the device flow calls.
func New(baseURL, token string, hc *http.Client, userAgent string) *Client {
	if hc == nil {
		hc = &http.Client{}
	}
	return &Client{
		BaseURL: strings.TrimRight(baseURL, "/"), Token: token, HTTP: hc, UserAgent: userAgent,
		Backoff: backoff, TimeoutFor: requestTimeout,
	}
}

// requestTimeout is max(30s, 30s + bodyBytes/16 KB/s); the complete call waits at least 60s.
func requestTimeout(path string, bodyBytes int) time.Duration {
	base := baseTimeout
	if strings.HasSuffix(path, "/complete") {
		base = completeTimeout
	}
	return base + time.Duration(bodyBytes)*time.Second/uplinkBytesPerSecond
}

// backoff sleeps 0.5s, 1s, 2s, 4s (capped at 8s) with equal jitter, and stops early when ctx ends.
func backoff(ctx context.Context, attempt int) error {
	d := 500 * time.Millisecond << min(max(attempt-1, 0), 4)
	d = d/2 + time.Duration(rand.Int64N(int64(d/2)+1))
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-t.C:
		return nil
	}
}

// Do sends a JSON request and decodes a JSON response into out (nil to discard).
func (c *Client) Do(ctx context.Context, method, path string, body, out any) error {
	var payload []byte
	if body != nil {
		b, err := json.Marshal(body)
		if err != nil {
			return err
		}
		payload = b
	}
	data, _, err := c.send(ctx, method, path, nil, payload)
	if err != nil {
		return err
	}
	if out == nil || len(bytes.TrimSpace(data)) == 0 {
		return nil
	}
	return json.Unmarshal(data, out)
}

// Raw returns the response body untouched, for octet-stream reads.
func (c *Client) Raw(ctx context.Context, method, path string, query url.Values) ([]byte, http.Header, error) {
	return c.send(ctx, method, path, query, nil)
}

// replaySafe says a POST or PUT to path can be sent again after a lost answer: the server treats a repeated
// append, complete, begin, cancel, checkpoint, file put or resolve as the same request.
func replaySafe(method, path string) bool {
	if method == http.MethodGet || method == http.MethodDelete {
		return true
	}
	return strings.HasPrefix(path, "/teleports") || strings.Contains(path, "/session/") || path == "/repos/resolve"
}

// retryable says whether a failed attempt is worth repeating: a transport error or timeout, 429, 502, 503, 504,
// and for idempotent reads any other 5xx.
func retryable(method string, err error) bool {
	e, ok := AsError(err)
	if !ok {
		return true
	}
	switch e.Status {
	case http.StatusTooManyRequests, http.StatusBadGateway, http.StatusServiceUnavailable, http.StatusGatewayTimeout:
		return true
	}
	return e.Status >= 500 && (method == http.MethodGet || method == http.MethodDelete)
}

func (c *Client) send(ctx context.Context, method, path string, query url.Values, payload []byte) ([]byte, http.Header, error) {
	timeout := c.TimeoutFor(path, len(payload))
	attempts := 1
	if replaySafe(method, path) {
		attempts = maxAttempts
	}
	var lastErr error
	for attempt := 1; attempt <= attempts; attempt++ {
		if attempt > 1 {
			if err := c.Backoff(ctx, attempt-1); err != nil {
				return nil, nil, err
			}
		}
		data, header, err := c.once(ctx, method, path, query, payload, timeout)
		if err == nil {
			return data, header, nil
		}
		if ctx.Err() != nil {
			return nil, nil, err
		}
		lastErr = err
		if !retryable(method, err) {
			return nil, nil, err
		}
	}
	return nil, nil, lastErr
}

func (c *Client) once(ctx context.Context, method, path string, query url.Values, payload []byte, timeout time.Duration) ([]byte, http.Header, error) {
	ctx, cancel := context.WithTimeout(ctx, timeout)
	defer cancel()
	u := c.BaseURL + basePath + path
	if len(query) > 0 {
		u += "?" + query.Encode()
	}
	var rdr io.Reader
	if payload != nil {
		rdr = bytes.NewReader(payload)
	}
	req, err := http.NewRequestWithContext(ctx, method, u, rdr)
	if err != nil {
		return nil, nil, err
	}
	req.Header.Set("Accept", "application/json")
	req.Header.Set("User-Agent", c.UserAgent)
	req.Header.Set("X-Yak-Protocol", strconv.Itoa(version.ProtocolVersion))
	if payload != nil {
		req.Header.Set("Content-Type", "application/json")
	}
	if c.Token != "" {
		req.Header.Set("Authorization", "Bearer "+c.Token)
	}
	resp, err := c.HTTP.Do(req)
	if err != nil {
		return nil, nil, err
	}
	defer resp.Body.Close()
	data, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, nil, err
	}
	if resp.StatusCode >= 400 {
		return nil, nil, parseError(resp.StatusCode, data)
	}
	return data, resp.Header, nil
}

func parseError(status int, body []byte) *Error {
	e := &Error{Status: status, Code: "server_error"}
	var env struct {
		Error   string          `json:"error"`
		Message string          `json:"message"`
		Details json.RawMessage `json:"details"`
	}
	_ = json.Unmarshal(body, &e.Body)
	if json.Unmarshal(body, &env) == nil && env.Error != "" {
		e.Code, e.Message = env.Error, env.Message
		var d map[string]any
		if json.Unmarshal(env.Details, &d) == nil {
			e.Details = d
		}
	}
	if status == http.StatusUpgradeRequired {
		e.Code = ErrProtocolTooOld.Code
		if e.Message == "" {
			e.Message = ErrProtocolTooOld.Message
		}
	}
	return e
}
