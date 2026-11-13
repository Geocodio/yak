package main

import (
	"bufio"
	"fmt"
	"os"
	"os/exec"
	"strings"
	"syscall"
	"testing"
	"time"
)

func readLine(t *testing.T, r *bufio.Reader) string {
	t.Helper()
	line, err := r.ReadString('\n')
	if err != nil {
		t.Fatalf("read: %v (%q)", err, line)
	}

	return strings.TrimSpace(line)
}

func TestPTYSizeAppliedAtStartAndAfterSetSize(t *testing.T) {
	cmd := exec.Command("sh", "-c", "stty size; read x; stty size; read x")
	master, err := StartWithPTY(cmd, 100, 30)
	if err != nil {
		t.Fatal(err)
	}
	defer master.Close()
	defer cmd.Process.Kill()

	reader := bufio.NewReader(master)
	if got := readLine(t, reader); got != "30 100" {
		t.Fatalf("initial size %q", got)
	}

	if err := SetSize(master, 80, 24); err != nil {
		t.Fatal(err)
	}
	master.Write([]byte("\n"))
	readLine(t, reader) // echoed newline
	if got := readLine(t, reader); got != "24 80" {
		t.Fatalf("resized %q", got)
	}
}

func TestPTYOutputRoundTrip(t *testing.T) {
	cmd := exec.Command("cat")
	master, err := StartWithPTY(cmd, 80, 24)
	if err != nil {
		t.Fatal(err)
	}
	defer master.Close()
	defer cmd.Process.Kill()

	master.Write([]byte("hello\n"))
	reader := bufio.NewReader(master)
	readLine(t, reader) // tty echo
	if got := readLine(t, reader); got != "hello" {
		t.Fatalf("got %q", got)
	}
}

func TestClosingMasterEndsChild(t *testing.T) {
	cmd := exec.Command("cat")
	master, err := StartWithPTY(cmd, 80, 24)
	if err != nil {
		t.Fatal(err)
	}
	master.Close()

	done := make(chan error, 1)
	go func() { done <- cmd.Wait() }()
	select {
	case <-done:
	case <-time.After(5 * time.Second):
		cmd.Process.Kill()
		t.Fatal("child still running after master close")
	}
}

// A service started by systemd is a session leader with no controlling terminal. Opening the
// PTY slave without O_NOCTTY would make it the bridge's own controlling terminal, so the
// child's TIOCSCTTY fails and the later hangup kills the bridge. The helper process below is
// that session leader.
func TestStartWithPTYFromSessionLeaderKeepsBridgeWithoutControllingTerminal(t *testing.T) {
	if os.Getenv("TERMBRIDGE_PTY_HELPER") == "1" {
		cmd := exec.Command("true")
		master, err := StartWithPTY(cmd, 80, 24)
		if err != nil {
			fmt.Println("start failed:", err)
			os.Exit(3)
		}
		defer master.Close()
		cmd.Wait()
		if _, err := os.OpenFile("/dev/tty", os.O_RDWR, 0); err == nil {
			fmt.Println("bridge acquired a controlling terminal")
			os.Exit(4)
		}
		os.Exit(0)
	}

	helper := exec.Command(os.Args[0], "-test.run=TestStartWithPTYFromSessionLeaderKeepsBridgeWithoutControllingTerminal")
	helper.Env = append(os.Environ(), "TERMBRIDGE_PTY_HELPER=1")
	helper.SysProcAttr = &syscall.SysProcAttr{Setsid: true}
	if output, err := helper.CombinedOutput(); err != nil {
		t.Fatalf("%v: %s", err, output)
	}
}
