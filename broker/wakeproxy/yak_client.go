package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"strings"
	"time"
)

// ErrAccessGone means Yak answered an activity ping with a non-200 status: the session is no longer allowed.
var ErrAccessGone = errors.New("yak no longer allows this session")

type statusError struct {
	path string
	code int
}

func (e *statusError) Error() string { return fmt.Sprintf("yak %s status %d", e.path, e.code) }

// Listener is one loopback address the daemon must bind.
type Listener struct {
	Address string `json:"address"`
	Port    int    `json:"port"`
}

// WakeReply is Yak's answer to a wake request: ready, waiting, destroyed, closed or no_sandbox.
type WakeReply struct {
	State     string  `json:"state"`
	Container *string `json:"container"`
	Message   *string `json:"message"`
}

// YakClient is the slice of the Yak internal ssh API the daemon uses.
type YakClient interface {
	Listeners(ctx context.Context) ([]Listener, error)
	Wake(ctx context.Context, address string) (WakeReply, error)
	Activity(ctx context.Context, address string) error
}

// HTTPYakClient talks to /internal/ssh/* with a bearer token.
type HTTPYakClient struct {
	BaseURL string
	Token   string
	HTTP    *http.Client
}

func (c *HTTPYakClient) do(ctx context.Context, method, path string, payload any, out any) error {
	var body *bytes.Reader
	if payload != nil {
		encoded, err := json.Marshal(payload)
		if err != nil {
			return err
		}
		body = bytes.NewReader(encoded)
	} else {
		body = bytes.NewReader(nil)
	}

	request, err := http.NewRequestWithContext(ctx, method, strings.TrimRight(c.BaseURL, "/")+"/internal/ssh/"+path, body)
	if err != nil {
		return err
	}
	request.Header.Set("Authorization", "Bearer "+c.Token)
	request.Header.Set("Accept", "application/json")
	if payload != nil {
		request.Header.Set("Content-Type", "application/json")
	}

	client := c.HTTP
	if client == nil {
		client = &http.Client{Timeout: 15 * time.Second}
	}

	response, err := client.Do(request)
	if err != nil {
		return fmt.Errorf("yak %s request failed", path)
	}
	defer response.Body.Close()

	if response.StatusCode != http.StatusOK {
		return &statusError{path: path, code: response.StatusCode}
	}

	if out == nil {
		return nil
	}

	return json.NewDecoder(response.Body).Decode(out)
}

func (c *HTTPYakClient) Listeners(ctx context.Context) ([]Listener, error) {
	var reply struct {
		Listeners []Listener `json:"listeners"`
	}
	if err := c.do(ctx, http.MethodGet, "listeners", nil, &reply); err != nil {
		return nil, err
	}

	return reply.Listeners, nil
}

func (c *HTTPYakClient) Wake(ctx context.Context, address string) (WakeReply, error) {
	var reply WakeReply
	err := c.do(ctx, http.MethodPost, "wake", map[string]string{"address": address}, &reply)

	return reply, err
}

func (c *HTTPYakClient) Activity(ctx context.Context, address string) error {
	err := c.do(ctx, http.MethodPost, "activity", map[string]string{"address": address}, nil)

	var status *statusError
	if errors.As(err, &status) {
		return fmt.Errorf("%w: %w", ErrAccessGone, err)
	}

	return err
}
