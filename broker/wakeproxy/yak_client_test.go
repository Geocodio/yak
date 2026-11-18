package main

import (
	"context"
	"net"
	"net/http"
	"net/http/httptest"
	"sync/atomic"
	"testing"
)

func TestHTTPYakClientOpensANewConnectionForEachRequest(t *testing.T) {
	var connections atomic.Int32
	server := httptest.NewUnstartedServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		writer.Header().Set("Content-Type", "application/json")
		_, _ = writer.Write([]byte(`{"listeners":[]}`))
	}))
	server.Config.ConnState = func(connection net.Conn, state http.ConnState) {
		if state == http.StateNew {
			connections.Add(1)
		}
	}
	server.Start()
	defer server.Close()

	client := &HTTPYakClient{BaseURL: server.URL, Token: "t"}
	for range 3 {
		if _, err := client.Listeners(context.Background()); err != nil {
			t.Fatalf("listeners: %v", err)
		}
	}

	if got := connections.Load(); got != 3 {
		t.Fatalf("connections = %d, want 3", got)
	}
}
