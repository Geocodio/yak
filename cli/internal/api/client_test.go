package api

import (
	"context"
	"errors"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
)

func newTestClient(t *testing.T, h http.HandlerFunc) *Client {
	t.Helper()
	srv := httptest.NewServer(h)
	t.Cleanup(srv.Close)
	c := New(srv.URL, "yakdev_tok", srv.Client(), "yak-cli/0.1.0 (darwin; arm64)")
	c.Backoff = func(int) {}
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

func TestGetRetriesTwiceOn500ThenFails(t *testing.T) {
	var n atomic.Int32
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		n.Add(1)
		w.WriteHeader(500)
	})
	err := c.Do(context.Background(), "GET", "/x", nil, nil)
	if !IsCode(err, "server_error") || n.Load() != 3 {
		t.Fatalf("calls %d, err %v", n.Load(), err)
	}
}

func TestPostIsNotRetried(t *testing.T) {
	var n atomic.Int32
	c := newTestClient(t, func(w http.ResponseWriter, r *http.Request) {
		n.Add(1)
		w.WriteHeader(500)
	})
	_ = c.Do(context.Background(), "POST", "/x", map[string]int{"a": 1}, nil)
	if n.Load() != 1 {
		t.Fatalf("calls %d", n.Load())
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
