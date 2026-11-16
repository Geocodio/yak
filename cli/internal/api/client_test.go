package api

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
	"time"
)

func newTestClient(t *testing.T, h http.HandlerFunc) *Client {
	t.Helper()
	srv := httptest.NewServer(h)
	t.Cleanup(srv.Close)
	c := New(srv.URL, "yakdev_tok", srv.Client(), "yak-cli/0.1.0 (darwin; arm64)")
	c.Backoff = func(context.Context, int) error { return nil }
	return c
}

func TestAuthHeaderAndUserAgent(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path != "/api/cli/v1/me" {
			t.Errorf("path %s", r.URL.Path)
		}
		if r.Header.Get("Authorization") != "Bearer yakdev_tok" ||
			r.Header.Get("User-Agent") != "yak-cli/0.1.0 (darwin; arm64)" ||
			r.Header.Get("X-Yak-Protocol") != "1" ||
			r.Header.Get("Accept") != "application/json" {
			t.Errorf("headers %v", r.Header)
		}
		w.Write([]byte(`{}`))
	})
	if err := c.Do(context.Background(), "GET", "/me", nil, &Me{}); err != nil {
		t.Fatal(err)
	}
}

func TestErrorEnvelopeParsed(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(409)
		w.Write([]byte(`{"error":"owned_by_other_device","message":"Nope.","details":{"device_name":"box"}}`))
	})
	err := c.Do(context.Background(), "GET", "/x", nil, nil)
	e, ok := AsError(err)
	if !ok || e.Status != 409 || e.Code != "owned_by_other_device" || e.Message != "Nope." || e.Details["device_name"] != "box" {
		t.Fatalf("%+v", e)
	}
	if !IsCode(err, "owned_by_other_device") || IsCode(err, "other") {
		t.Fatal("IsCode")
	}
}

func TestEmptyDetailsArrayTolerated(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(404)
		w.Write([]byte(`{"error":"thread_not_found","message":"m","details":[]}`))
	})
	if !IsCode(c.Do(context.Background(), "GET", "/x", nil, nil), "thread_not_found") {
		t.Fatal("details [] broke parsing")
	}
}

func TestNonJSONErrorBodyIsServerError(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(400)
		w.Write([]byte(`<html>bad gateway</html>`))
	})
	if !IsCode(c.Do(context.Background(), "POST", "/x", map[string]int{}, nil), "server_error") {
		t.Fatal("want server_error")
	}
}

func Test426ReturnsProtocolTooOld(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(426)
		w.Write([]byte(`{"error":"protocol_too_old","message":"Update the Yak plugin.","details":{"min_protocol":2}}`))
	})
	err := c.Do(context.Background(), "GET", "/me", nil, nil)
	if !errors.Is(err, ErrProtocolTooOld) {
		t.Fatalf("%v", err)
	}
	if e, _ := AsError(err); e.Message != "Update the Yak plugin." {
		t.Fatalf("message %q", e.Message)
	}
	c2 := newTestClient(t, func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(426) })
	if !errors.Is(c2.Do(context.Background(), "GET", "/me", nil, nil), ErrProtocolTooOld) {
		t.Fatal("empty 426 body must still map")
	}
}

func TestGetRetriesOn500ThenFails(t *testing.T) {
	var n atomic.Int32
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		n.Add(1)
		w.WriteHeader(500)
	})
	err := c.Do(context.Background(), "GET", "/x", nil, nil)
	if !IsCode(err, "server_error") || n.Load() != maxAttempts {
		t.Fatalf("calls %d, err %v", n.Load(), err)
	}
}

func TestPostOutsideSessionAndTeleportsIsNotRetried(t *testing.T) {
	var n atomic.Int32
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		n.Add(1)
		w.WriteHeader(503)
	})
	_ = c.Do(context.Background(), "POST", "/x", map[string]int{"a": 1}, nil)
	if n.Load() != 1 {
		t.Fatalf("calls %d", n.Load())
	}
}

func TestPostToTeleportsRetriesGatewayErrorsButNotPlain500(t *testing.T) {
	var n atomic.Int32
	status := 502
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		if n.Add(1) < 3 {
			w.WriteHeader(status)
			return
		}
		w.Write([]byte(`{}`))
	})
	if err := c.Do(context.Background(), "POST", "/teleports/abc/complete", map[string]int{}, nil); err != nil || n.Load() != 3 {
		t.Fatalf("calls %d, err %v", n.Load(), err)
	}
	n.Store(0)
	status = 500
	_ = c.Do(context.Background(), "POST", "/threads/1/session/append", map[string]int{}, nil)
	if n.Load() != 1 {
		t.Fatalf("a plain 500 on a POST must not be retried, calls %d", n.Load())
	}
}

func TestTransportErrorIsRetriedOnGetAndPost(t *testing.T) {
	for _, tc := range []struct{ method, path string }{{"GET", "/threads/1/session/length"}, {"POST", "/threads/1/session/append"}} {
		var n atomic.Int32
		c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
			if n.Add(1) < 3 {
				conn, _, _ := w.(http.Hijacker).Hijack()
				conn.Close()
				return
			}
			w.Write([]byte(`{"length":7}`))
		})
		var out struct{ Length int }
		if err := c.Do(context.Background(), tc.method, tc.path, map[string]int{}, &out); err != nil || out.Length != 7 || n.Load() != 3 {
			t.Fatalf("%s: calls %d, out %+v, err %v", tc.method, n.Load(), out, err)
		}
	}
}

func TestTimeoutIsRetried(t *testing.T) {
	var n atomic.Int32
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		if n.Add(1) == 1 {
			<-r.Context().Done()
			return
		}
		w.Write([]byte(`{}`))
	})
	c.TimeoutFor = func(string, int) time.Duration { return 50 * time.Millisecond }
	if err := c.Do(context.Background(), "GET", "/me", nil, nil); err != nil || n.Load() != 2 {
		t.Fatalf("calls %d, err %v", n.Load(), err)
	}
}

func TestCancelledContextStopsRetrying(t *testing.T) {
	var n atomic.Int32
	ctx, cancel := context.WithCancel(context.Background())
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		n.Add(1)
		cancel()
		w.WriteHeader(503)
	})
	if err := c.Do(ctx, "GET", "/me", nil, nil); err == nil || n.Load() != 1 {
		t.Fatalf("calls %d, err %v", n.Load(), err)
	}
}

func TestBackoffStopsWhenContextEnds(t *testing.T) {
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if err := backoff(ctx, 4); !errors.Is(err, context.Canceled) {
		t.Fatalf("%v", err)
	}
}

func TestRequestTimeoutScalesWithBody(t *testing.T) {
	if got := requestTimeout("/me", 0); got != 30*time.Second {
		t.Fatalf("%v", got)
	}
	if got := requestTimeout("/teleports/x/complete", 0); got != 60*time.Second {
		t.Fatalf("%v", got)
	}
	if got := requestTimeout("/threads/1/session/append", 16*1024*10); got != 40*time.Second {
		t.Fatalf("%v", got)
	}
}

func TestUnknownFieldsIgnored(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte(`{"user":{"id":3,"name":"M","email":"m@x","future":1},"surprise":true}`))
	})
	var me Me
	if err := c.Do(context.Background(), "GET", "/me", nil, &me); err != nil || me.User.Email != "m@x" {
		t.Fatalf("%+v %v", me, err)
	}
}

func TestRawReturnsBytesAndQuery(t *testing.T) {
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Query().Get("offset") != "5" {
			t.Errorf("query %v", r.URL.RawQuery)
		}
		w.Header().Set("Content-Type", "application/octet-stream")
		w.Write([]byte("abc"))
	})
	b, _, err := c.Raw(context.Background(), "GET", "/threads/1/session/read", map[string][]string{"offset": {"5"}})
	if err != nil || string(b) != "abc" {
		t.Fatalf("%q %v", b, err)
	}
}

func TestManifestDecodesMultiRepoFields(t *testing.T) {
	raw := `{"repos":[{"repo":"acme/api","role":"changes","status":"failed","pushed":false,"head_sha":null,
		"failure":{"code":"push_failed","message":"push rejected"},"refresh":[{"glob":"composer.lock","command":"composer install"}]}],
		"context_repos":["acme/docs"]}`
	var m DownManifest
	if err := json.Unmarshal([]byte(raw), &m); err != nil {
		t.Fatal(err)
	}
	r := m.Repos[0]
	if r.Status != "failed" || r.Failure == nil || r.Failure.Code != "push_failed" || r.Refresh[0].Command != "composer install" || m.ContextRepos[0] != "acme/docs" {
		t.Fatalf("%+v", m)
	}
	var done CompleteUpResponse
	if err := json.Unmarshal([]byte(`{"sandbox":{"state":"ready","repos":[{"repo":"acme/api","role":"context","status":"failed","reason":"clone failed"}]}}`), &done); err != nil {
		t.Fatal(err)
	}
	if done.Sandbox.Repos[0].Reason != "clone failed" {
		t.Fatalf("%+v", done)
	}
}
