package main

import (
	"bufio"
	"context"
	"net"
	"os"
	"path/filepath"
	"testing"
)

func serveOnce(t *testing.T, reply string) (path string, requests chan string) {
	t.Helper()
	directory, err := os.MkdirTemp("/tmp", "yb")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { os.RemoveAll(directory) })
	path = filepath.Join(directory, "r.sock")
	listener, err := net.Listen("unix", path)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { listener.Close() })
	requests = make(chan string, 1)
	go func() {
		connection, err := listener.Accept()
		if err != nil {
			return
		}
		defer connection.Close()
		line, _ := bufio.NewReader(connection).ReadString('\n')
		requests <- line
		_, _ = connection.Write([]byte(reply + "\n"))
	}()
	return path, requests
}

func TestSocketRedeemerReturnsTheCredential(t *testing.T) {
	path, requests := serveOnce(t, `{"type":"api_key","value":"key-1"}`)
	credential, err := SocketRedeemer{Path: path}.Redeem(context.Background(), "ref-1")
	if err != nil || credential.Type != "api_key" || credential.Value != "key-1" {
		t.Fatalf("credential = %v, err = %v", credential, err)
	}
	if got := <-requests; got != "{\"reference\":\"ref-1\"}\n" {
		t.Fatalf("request = %q", got)
	}
}

func TestSocketRedeemerReportsRefusals(t *testing.T) {
	path, _ := serveOnce(t, `{"error":"expired"}`)
	if _, err := (SocketRedeemer{Path: path}).Redeem(context.Background(), "ref-1"); err == nil {
		t.Fatal("a refusal was accepted")
	}
}
