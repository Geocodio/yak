package main

import (
	"bufio"
	"context"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"testing"
	"time"
)

var loopbackCredentials = map[string]Credential{"yakph-a|127.0.0.1": {Kind: "setup_token", Secret: "real-secret-value"}}

func (h *proxyHarness) front() *httptest.Server {
	front := httptest.NewServer(h.proxy)
	h.t.Cleanup(front.Close)
	return front
}

func postThroughFront(ctx context.Context, front *httptest.Server) (*http.Response, error) {
	request, _ := http.NewRequestWithContext(ctx, "POST", front.URL+"/v1/messages", strings.NewReader("{}"))
	request.Header.Set("Authorization", "Bearer yakph-a")
	return http.DefaultClient.Do(request)
}

func TestProxyStreamsSSEWithoutBuffering(t *testing.T) {
	h := newProxyHarness(t, loopbackCredentials)
	release := make(chan struct{})
	h.respond = func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/event-stream")
		_, _ = io.WriteString(w, "event: message_start\ndata: {}\n\n")
		w.(http.Flusher).Flush()
		<-release
		_, _ = io.WriteString(w, "event: message_stop\ndata: {}\n\n")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()
	response, err := postThroughFront(ctx, h.front())
	if err != nil {
		t.Fatal(err)
	}
	defer response.Body.Close()
	reader := bufio.NewReader(response.Body)
	first, _ := reader.ReadString('\n')
	if first != "event: message_start\n" {
		t.Fatalf("first chunk %q", first)
	}
	close(release)
	rest, _ := io.ReadAll(reader)
	if !strings.Contains(string(rest), "event: message_stop") {
		t.Fatalf("rest %q", rest)
	}
}

func TestProxyRelaysAnSSEErrorEventInA200Stream(t *testing.T) {
	h := newProxyHarness(t, loopbackCredentials)
	event := "event: error\ndata: {\"type\":\"error\",\"error\":{\"type\":\"overloaded_error\",\"message\":\"PROMPT-TEXT\"}}\n\n"
	h.respond = func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/event-stream")
		_, _ = io.WriteString(w, event)
	}
	response, err := postThroughFront(context.Background(), h.front())
	if err != nil {
		t.Fatal(err)
	}
	body, _ := io.ReadAll(response.Body)
	if string(body) != event || response.StatusCode != 200 {
		t.Fatalf("%d %q", response.StatusCode, body)
	}
	if strings.Contains(h.logs.String(), "non-200") {
		t.Fatalf("logged: %s", h.logs)
	}
}

func TestProxyHandlesAMidStreamUpstreamDrop(t *testing.T) {
	h := newProxyHarness(t, loopbackCredentials)
	h.respond = func(w http.ResponseWriter, r *http.Request) {
		connection, buffer, err := w.(http.Hijacker).Hijack()
		if err != nil {
			return
		}
		_, _ = buffer.WriteString("HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n")
		_, _ = buffer.WriteString("20\r\nevent: message_start\ndata: {}\n\n\r\n40\r\nevent: content_blo")
		_ = buffer.Flush()
		_ = connection.(*net.TCPConn).CloseRead()
		_ = connection.Close()
	}
	ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
	defer cancel()
	response, err := postThroughFront(ctx, h.front())
	if err != nil {
		t.Fatal(err)
	}
	body, readErr := io.ReadAll(response.Body)
	if !strings.HasPrefix(string(body), "event: message_start") {
		t.Fatalf("body %q", body)
	}
	if readErr == nil && !strings.HasSuffix(string(body), "\n\n") {
		t.Log("short body without error")
	}
	if strings.Contains(h.logs.String(), "real-secret-value") || strings.Contains(h.logs.String(), "yakph-a") {
		t.Fatalf("log leaks: %s", h.logs)
	}
}

func TestProxyLogsEveryNon200WithShape(t *testing.T) {
	for _, status := range []int{400, 401, 404, 429, 500, 529} {
		h := newProxyHarness(t, validCredentials)
		h.respond = func(w http.ResponseWriter, r *http.Request) {
			w.Header().Set("Content-Type", "application/json")
			w.Header().Set("Retry-After", "17")
			w.Header().Set("Anthropic-Ratelimit-Unified-Status", "rejected")
			w.Header().Set("Set-Cookie", "session=COOKIE-VALUE")
			w.WriteHeader(status)
			_, _ = io.WriteString(w, `{"type":"error","error":{"type":"rate_limit_error","message":"PROMPT-TEXT"}}`)
		}
		sandboxRequest(h, nil)
		log := h.logs.String()
		if strings.Count(log, "upstream non-200") != 1 {
			t.Fatalf("%d: %s", status, log)
		}
		for _, want := range []string{"status=" + strconv.Itoa(status), "error.type=rate_limit_error", "error.message", "Retry-After=17", "Anthropic-Ratelimit-Unified-Status=rejected", "Set-Cookie", "body_bytes=76"} {
			if !strings.Contains(log, want) {
				t.Fatalf("%d: missing %q in %s", status, want, log)
			}
		}
		for _, forbidden := range []string{"PROMPT-TEXT", "COOKIE-VALUE", "real-token", "yakph-a"} {
			if strings.Contains(log, forbidden) {
				t.Fatalf("%d: log contains %q: %s", status, forbidden, log)
			}
		}
	}
}

func TestProxyLogsNothingForA200(t *testing.T) {
	h := newProxyHarness(t, validCredentials)
	sandboxRequest(h, nil)
	if strings.Contains(h.logs.String(), "non-200") {
		t.Fatalf("logged: %s", h.logs)
	}
}

func TestProxyNeverLogsOrReturnsTheSecret(t *testing.T) {
	h := newProxyHarness(t, validCredentials)
	var bodies []string
	collect := func(response *httptest.ResponseRecorder) { bodies = append(bodies, response.Body.String()) }
	collect(h.do("POST", "/v1/messages", "Bearer yakph-unknown", "10.0.0.5"))
	collect(h.do("POST", "/nope", "Bearer yakph-a", "10.0.0.5"))
	h.respond = func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusBadRequest)
		_, _ = io.WriteString(w, "not json")
	}
	collect(sandboxRequest(h, nil))
	h.upstream.Close()
	collect(sandboxRequest(h, nil))
	h.source.failure = io.ErrUnexpectedEOF
	h.proxy.Revoke("yakph-a")
	collect(sandboxRequest(h, nil))
	all := h.logs.String() + strings.Join(bodies, "\n")
	for _, forbidden := range []string{"real-token", "yakph-a", "yakph-unknown"} {
		if strings.Contains(all, forbidden) {
			t.Fatalf("leaked %q: %s", forbidden, all)
		}
	}
}
