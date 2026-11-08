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

func TestSocketSourceReturnsTheCredential(t *testing.T) {
	path, requests := serveOnce(t, `{"type":"api_key","value":"key-1","container":"c1"}`)
	credential, err := SocketCredentialSource{Path: path}.Lookup(context.Background(), "yakph-1")
	if err != nil || credential.Type != "api_key" || credential.Value != "key-1" || credential.Container != "c1" {
		t.Fatalf("credential = %v, err = %v", credential, err)
	}
	if got := <-requests; got != "{\"placeholder\":\"yakph-1\"}\n" {
		t.Fatalf("request = %q", got)
	}
}

func TestSocketSourceReportsRefusals(t *testing.T) {
	path, _ := serveOnce(t, `{"error":"expired"}`)
	if _, err := (SocketCredentialSource{Path: path}).Lookup(context.Background(), "yakph-1"); err == nil {
		t.Fatal("a refusal was accepted")
	}
}
