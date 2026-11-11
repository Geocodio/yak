package main

import (
	"os"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"testing"
	"time"
)

// fakeSSH writes a shell script that stands in for ssh and records its pid and argv.
func fakeSSH(t *testing.T) (bin, pidFile, argsFile string) {
	t.Helper()
	dir := t.TempDir()
	bin = filepath.Join(dir, "ssh")
	pidFile = filepath.Join(dir, "pid")
	argsFile = filepath.Join(dir, "args")
	script := `#!/bin/sh
echo $$ > ` + pidFile + `
echo "$@" > ` + argsFile + `
echo ready
while read line; do
  case "$line" in
    size) stty size ;;
    env) env ;;
    exit) exit 3 ;;
    *) echo "got:$line" ;;
  esac
done
`
	if err := os.WriteFile(bin, []byte(script), 0o755); err != nil {
		t.Fatal(err)
	}

	return bin, pidFile, argsFile
}

func runSession(t *testing.T, session *Session) (*testClient, chan struct{}, chan struct{}) {
	t.Helper()
	stop := make(chan struct{})
	finished := make(chan struct{})
	server := newServer(t, UpgradeOptions{CheckOrigin: allowAll}, func(c *Conn) {
		defer close(finished)
		session.Run(stop, c, Grant{Address: "127.16.0.1"}, "/nonexistent/key", 80, 24)
	})
	client, response := dial(t, server, "")
	if response.StatusCode != 101 {
		t.Fatalf("status %d", response.StatusCode)
	}

	return client, stop, finished
}

// readUntil collects binary output until it contains want.
func readUntil(t *testing.T, client *testClient, want string) string {
	t.Helper()
	var collected string
	for !strings.Contains(collected, want) {
		op, payload := client.read(t)
		if op == opBinary {
			collected += string(payload)
		} else if op == opClose {
			t.Fatalf("closed %d while waiting for %q (got %q)", closeCode(payload), want, collected)
		}
	}

	return collected
}

func TestSSHArgsAreFixed(t *testing.T) {
	args := strings.Join(SSHArgs("/k", "/h/known_hosts", "127.16.0.9"), " ")
	for _, want := range []string{"-tt", "-p 22", "-i /k", "IdentitiesOnly=yes", "BatchMode=yes", "StrictHostKeyChecking=accept-new",
		"UserKnownHostsFile=/h/known_hosts", "ServerAliveInterval=15", "ConnectTimeout=30", "LogLevel=ERROR", "yak@127.16.0.9"} {
		if !strings.Contains(args, want) {
			t.Errorf("missing %q in %q", want, args)
		}
	}
}

func TestKeystrokesOutputAndResize(t *testing.T) {
	bin, _, _ := fakeSSH(t)
	client, _, _ := runSession(t, &Session{SSHBin: bin})

	readUntil(t, client, "ready")
	client.send(true, opBinary, []byte("hello\n"), true)
	readUntil(t, client, "got:hello")

	client.send(true, opText, []byte(`{"type":"resize","cols":120,"rows":40}`), true)
	client.send(true, opBinary, []byte("size\n"), true)
	readUntil(t, client, "40 120")

	client.send(true, opText, []byte(`{"type":"ping"}`), true)
	client.send(true, opBinary, []byte("again\n"), true)
	readUntil(t, client, "got:again")
}

func TestEnvironmentIsScrubbed(t *testing.T) {
	t.Setenv("SSH_AUTH_SOCK", "/tmp/agent.sock")
	t.Setenv("SECRET_THING", "nope")
	bin, _, _ := fakeSSH(t)
	client, _, _ := runSession(t, &Session{SSHBin: bin})

	readUntil(t, client, "ready")
	client.send(true, opBinary, []byte("env\n"), true)
	output := readUntil(t, client, "TERM=xterm-256color")
	// Give the rest of the env listing a moment to arrive.
	time.Sleep(200 * time.Millisecond)

	if strings.Contains(output, "SSH_AUTH_SOCK") || strings.Contains(output, "SECRET_THING") {
		t.Fatalf("environment leaked: %q", output)
	}
}

func TestBadControlMessageClosesWith1003(t *testing.T) {
	bin, _, _ := fakeSSH(t)
	for _, body := range []string{`not json`, `{"type":"nope"}`, `{"type":"resize","cols":0,"rows":10}`, `{"type":"resize","cols":10,"rows":501}`} {
		client, _, _ := runSession(t, &Session{SSHBin: bin})
		readUntil(t, client, "ready")
		client.send(true, opText, []byte(body), true)

		for {
			op, payload := client.read(t)
			if op == opClose {
				if closeCode(payload) != 1003 {
					t.Fatalf("%s closed %d", body, closeCode(payload))
				}
				break
			}
		}
	}
}

func TestProcessExitSendsExitMessageThenClose(t *testing.T) {
	bin, _, _ := fakeSSH(t)
	client, _, _ := runSession(t, &Session{SSHBin: bin})

	readUntil(t, client, "ready")
	client.send(true, opBinary, []byte("exit\n"), true)

	var sawExit bool
	for {
		op, payload := client.read(t)
		if op == opText {
			sawExit = string(payload) == `{"type":"exit","code":3}`
		}
		if op == opClose {
			if !sawExit || closeCode(payload) != 1000 {
				t.Fatalf("sawExit %v code %d", sawExit, closeCode(payload))
			}
			return
		}
	}
}

func TestClientCloseKillsChild(t *testing.T) {
	bin, pidFile, _ := fakeSSH(t)
	client, _, finished := runSession(t, &Session{SSHBin: bin})

	readUntil(t, client, "ready")
	raw, err := os.ReadFile(pidFile)
	if err != nil {
		t.Fatal(err)
	}
	pid, _ := strconv.Atoi(strings.TrimSpace(string(raw)))

	client.conn.Close()
	select {
	case <-finished:
	case <-time.After(5 * time.Second):
		t.Fatal("session did not end")
	}

	deadline := time.Now().Add(3 * time.Second)
	for time.Now().Before(deadline) {
		if syscall.Kill(pid, 0) != nil {
			return
		}
		time.Sleep(50 * time.Millisecond)
	}
	t.Fatal("child process still alive")
}

func TestIdleTimeoutClosesWith4408(t *testing.T) {
	bin, _, _ := fakeSSH(t)
	client, _, _ := runSession(t, &Session{SSHBin: bin, IdleTimeout: 300 * time.Millisecond})

	for {
		op, payload := client.read(t)
		if op == opClose {
			if closeCode(payload) != 4408 {
				t.Fatalf("closed %d", closeCode(payload))
			}
			return
		}
	}
}
