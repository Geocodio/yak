package main

import (
	"bufio"
	"bytes"
	"context"
	"errors"
	"fmt"
	"log/slog"
	"net"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestCredentialNeverPrintsItsSecret(t *testing.T) {
	credential := Credential{Kind: "api_key", Secret: "sk-ant-SECRET", AccountID: 3}

	outputs := []string{
		fmt.Sprintf("%v", credential),
		fmt.Sprintf("%+v", credential),
		fmt.Sprintf("%#v", credential),
		fmt.Sprintf("%s", credential),
		fmt.Sprintf("%v", &credential),
	}
	var text, structured bytes.Buffer
	slog.New(slog.NewTextHandler(&text, nil)).Info("lookup", "credential", credential)
	slog.New(slog.NewJSONHandler(&structured, nil)).Info("lookup", "credential", credential)
	outputs = append(outputs, text.String(), structured.String())

	for _, output := range outputs {
		if strings.Contains(output, "SECRET") {
			t.Errorf("output leaks the secret: %s", output)
		}
	}
}

// serveSocket answers each connection by calling handler with the request line.
// A reply of "" closes the connection without answering.
func serveSocket(t *testing.T, handler func(request string) string) string {
	t.Helper()
	directory, err := os.MkdirTemp("", "yb")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { os.RemoveAll(directory) })
	path := filepath.Join(directory, "c.sock")
	listener, err := net.Listen("unix", path)
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { listener.Close() })
	go func() {
		for {
			connection, err := listener.Accept()
			if err != nil {
				return
			}
			go func() {
				defer connection.Close()
				line, _ := bufio.NewReader(connection).ReadString('\n')
				if reply := handler(strings.TrimSuffix(line, "\n")); reply != "" {
					_, _ = connection.Write([]byte(reply + "\n"))
				}
			}()
		}
	}()
	return path
}

func TestSocketLookupSendsPlaceholderAndContainerIP(t *testing.T) {
	requests := make(chan string, 1)
	path := serveSocket(t, func(request string) string {
		requests <- request
		return `{"kind":"setup_token","secret":"tok","account_id":7}`
	})

	credential, err := SocketCredentialSource{Path: path}.Lookup(context.Background(), "yakph-1", "10.0.0.5")
	if err != nil {
		t.Fatal(err)
	}
	if credential != (Credential{"setup_token", "tok", 7}) {
		t.Fatalf("credential = %#v", credential.Kind)
	}
	if got := <-requests; got != `{"placeholder":"yakph-1","container_ip":"10.0.0.5"}` {
		t.Fatalf("request = %q", got)
	}
}

func TestSocketLookupMapsUnknown(t *testing.T) {
	path := serveSocket(t, func(string) string { return `{"error":"unknown"}` })
	_, err := SocketCredentialSource{Path: path}.Lookup(context.Background(), "yakph-1", "10.0.0.5")
	if !errors.Is(err, ErrUnknownPlaceholder) {
		t.Fatalf("err = %v", err)
	}
}

func TestSocketLookupRejectsBadReplies(t *testing.T) {
	replies := map[string]string{
		"non-json":     `not json with SECRET`,
		"empty secret": `{"kind":"api_key","secret":"","account_id":1}`,
		"bad kind":     `{"kind":"password","secret":"SECRET","account_id":1}`,
		"closed":       ``,
	}
	for name, reply := range replies {
		t.Run(name, func(t *testing.T) {
			path := serveSocket(t, func(string) string { return reply })
			_, err := SocketCredentialSource{Path: path}.Lookup(context.Background(), "yakph-1", "10.0.0.5")
			if err == nil || errors.Is(err, ErrUnknownPlaceholder) {
				t.Fatalf("err = %v", err)
			}
			if strings.Contains(err.Error(), "SECRET") {
				t.Fatalf("error leaks reply: %v", err)
			}
		})
	}
}

func TestSocketLookupMissingSocket(t *testing.T) {
	_, err := SocketCredentialSource{Path: filepath.Join(t.TempDir(), "none.sock")}.Lookup(context.Background(), "yakph-1", "10.0.0.5")
	if err == nil || errors.Is(err, ErrUnknownPlaceholder) {
		t.Fatalf("err = %v", err)
	}
}

func TestSocketLookupHonoursContext(t *testing.T) {
	release := make(chan struct{})
	t.Cleanup(func() { close(release) })
	path := serveSocket(t, func(string) string {
		<-release
		return ""
	})
	ctx, cancel := context.WithTimeout(context.Background(), 50*time.Millisecond)
	defer cancel()

	done := make(chan error, 1)
	go func() {
		_, err := SocketCredentialSource{Path: path}.Lookup(ctx, "yakph-1", "10.0.0.5")
		done <- err
	}()
	select {
	case err := <-done:
		if err == nil {
			t.Fatal("expected an error")
		}
	case <-time.After(2 * time.Second):
		t.Fatal("Lookup ignored the context deadline")
	}
}
