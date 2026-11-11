package main

import (
	"context"
	"io"
	"log/slog"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

var discardLogger = slog.New(slog.NewTextHandler(io.Discard, nil))

func TestStartProxyListens(t *testing.T) {
	var seenAuthorization string
	upstream := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		seenAuthorization = r.Header.Get("Authorization")
		w.WriteHeader(http.StatusOK)
	}))
	t.Cleanup(upstream.Close)
	socketPath := serveSocket(t, func(string) string {
		return `{"kind":"setup_token","secret":"real-token","account_id":7}`
	})
	t.Setenv("YAK_BROKER_PROXY_ADDR", "127.0.0.1:0")
	t.Setenv("YAK_BROKER_PROXY_UPSTREAM", upstream.URL)

	ctx, cancel := context.WithCancel(context.Background())
	handle, err := startProxy(ctx, socketPath, discardLogger)
	if err != nil {
		t.Fatal(err)
	}
	if handle.Revoker == nil {
		t.Fatal("no revoker")
	}
	request, _ := http.NewRequest("POST", "http://"+handle.Addr.String()+"/v1/messages", strings.NewReader(`{}`))
	request.Header.Set("Authorization", "Bearer yakph-x")
	response, err := http.DefaultClient.Do(request)
	if err != nil {
		t.Fatal(err)
	}
	response.Body.Close()
	if response.StatusCode != http.StatusOK || seenAuthorization != "Bearer real-token" {
		t.Fatalf("status %d, upstream saw %q", response.StatusCode, seenAuthorization)
	}

	cancel()
	deadline := time.Now().Add(3 * time.Second)
	for {
		connection, err := net.DialTimeout("tcp", handle.Addr.String(), 200*time.Millisecond)
		if err != nil {
			return
		}
		connection.Close()
		if time.Now().After(deadline) {
			t.Fatal("listener still open after cancel")
		}
		time.Sleep(50 * time.Millisecond)
	}
}

func TestStartProxyRejectsBadConfiguration(t *testing.T) {
	cases := map[string]map[string]string{
		"unset address": {},
		"bad cidr":      {"YAK_BROKER_PROXY_ADDR": "127.0.0.1:0", "YAK_BROKER_PROXY_HOST_CIDRS": "10.0.0.0/8,nope"},
		"bad listen":    {"YAK_BROKER_PROXY_ADDR": "256.0.0.1:99999"},
		"bad upstream":  {"YAK_BROKER_PROXY_ADDR": "127.0.0.1:0", "YAK_BROKER_PROXY_UPSTREAM": "not a url"},
	}
	for name, env := range cases {
		t.Run(name, func(t *testing.T) {
			for _, key := range []string{"YAK_BROKER_PROXY_ADDR", "YAK_BROKER_PROXY_HOST_CIDRS", "YAK_BROKER_PROXY_UPSTREAM"} {
				t.Setenv(key, env[key])
			}
			if _, err := startProxy(context.Background(), "/nonexistent.sock", discardLogger); err == nil {
				t.Fatal("expected an error")
			}
		})
	}
}

type fakeConn struct {
	net.Conn
	remote net.Addr
	closed bool
}

func (c *fakeConn) RemoteAddr() net.Addr { return c.remote }
func (c *fakeConn) Close() error         { c.closed = true; return nil }

type fakeListener struct {
	net.Listener
	conns chan net.Conn
}

func (l *fakeListener) Accept() (net.Conn, error) {
	conn, ok := <-l.conns
	if !ok {
		return nil, net.ErrClosed
	}
	return conn, nil
}

func TestPeerFilter(t *testing.T) {
	_, host, _ := net.ParseCIDR("172.16.0.0/12")
	_, bridge, _ := net.ParseCIDR("10.100.0.0/24")
	inside := &fakeConn{remote: &net.TCPAddr{IP: net.ParseIP("10.100.0.7"), Port: 1}}
	viaHost := &fakeConn{remote: &net.TCPAddr{IP: net.ParseIP("172.20.0.2"), Port: 1}}
	outside := &fakeConn{remote: &net.TCPAddr{IP: net.ParseIP("203.0.113.9"), Port: 1}}
	inner := &fakeListener{conns: make(chan net.Conn, 3)}
	inner.conns <- outside
	inner.conns <- inside
	inner.conns <- viaHost
	filtered := newPeerFilterListener(inner, []*net.IPNet{host}, []*net.IPNet{bridge})

	first, err := filtered.Accept()
	if err != nil || first != net.Conn(inside) {
		t.Fatalf("first accepted %v, %v; want the bridge peer", first, err)
	}
	if !outside.closed {
		t.Fatal("outside peer was not closed")
	}
	second, _ := filtered.Accept()
	if second != net.Conn(viaHost) || viaHost.closed {
		t.Fatal("host CIDR peer was not accepted")
	}
}

func TestMainWiringStartsListener(t *testing.T) {
	socketPath := serveSocket(t, func(string) string { return `{"error":"unknown"}` })
	t.Setenv("YAK_BROKER_PROXY_ADDR", "127.0.0.1:0")
	t.Setenv("YAK_BROKER_CREDENTIAL_SOCKET", socketPath)
	ctx, cancel := context.WithCancel(context.Background())
	t.Cleanup(cancel)
	handle, err := runProxy(ctx, discardLogger)
	if err != nil || handle.Revoker == nil {
		t.Fatalf("runProxy: %v", err)
	}
	connection, err := net.DialTimeout("tcp", handle.Addr.String(), time.Second)
	if err != nil {
		t.Fatal(err)
	}
	connection.Close()
}
