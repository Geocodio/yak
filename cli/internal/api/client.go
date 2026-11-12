// Package api is the client for /api/cli/v1.
package api

import (
	"bytes"
	"context"
	"encoding/json"
	"io"
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
	// Backoff runs between retries of idempotent calls; tests replace it.
	Backoff func(attempt int)
}

// New builds a client; token may be empty for the device flow calls.
func New(baseURL, token string, hc *http.Client, userAgent string) *Client {
	if hc == nil {
		hc = &http.Client{}
	}
	return &Client{
		BaseURL: strings.TrimRight(baseURL, "/"), Token: token, HTTP: hc, UserAgent: userAgent,
		Backoff: func(attempt int) { time.Sleep(time.Duration(attempt) * 500 * time.Millisecond) },
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

func (c *Client) send(ctx context.Context, method, path string, query url.Values, payload []byte) ([]byte, http.Header, error) {
	timeout := 15 * time.Second
	if strings.HasSuffix(path, "/complete") {
		timeout = 60 * time.Second
	}
	retries := 0
	if method == http.MethodGet || method == http.MethodDelete {
		retries = 2
	}
	var lastErr error
	for attempt := 0; attempt <= retries; attempt++ {
		if attempt > 0 {
			c.Backoff(attempt)
		}
		data, header, err := c.once(ctx, method, path, query, payload, timeout)
		if err == nil {
			return data, header, nil
		}
		lastErr = err
		if e, ok := AsError(err); !ok || e.Status < 500 {
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
