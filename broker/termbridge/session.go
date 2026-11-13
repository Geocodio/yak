package main

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"os"
	"os/exec"
	"path/filepath"
	"syscall"
	"time"
)

// Terminal size bounds accepted from the browser.
const (
	minDimension = 1
	maxDimension = 500
)

// Session runs one local ssh client under a PTY and pumps it to a WebSocket.
type Session struct {
	// SSHBin is the ssh binary; empty means "ssh" from PATH.
	SSHBin string
	// Path is the PATH handed to ssh; empty means the bridge's own PATH.
	Path string
	// IdleTimeout closes a session that sends no frames for this long; zero disables it.
	IdleTimeout time.Duration
	// DoneCloseCode picks the close code sent when done fires; nil means 1001.
	DoneCloseCode func() int
}

// SSHArgs is the ssh argument vector for a terminal session. The key is the ephemeral
// key; host keys are remembered only for the life of one session.
func SSHArgs(keyPath, knownHostsPath, address string) []string {
	return []string{
		"-tt", "-p", "22",
		"-i", keyPath,
		"-o", "IdentitiesOnly=yes",
		"-o", "BatchMode=yes",
		"-o", "StrictHostKeyChecking=accept-new",
		"-o", "UserKnownHostsFile=" + knownHostsPath,
		"-o", "ServerAliveInterval=15",
		"-o", "ConnectTimeout=30",
		"-o", "LogLevel=ERROR",
		"yak@" + address,
	}
}

type controlMessage struct {
	Type string `json:"type"`
	Cols int    `json:"cols"`
	Rows int    `json:"rows"`
}

// Run blocks until ssh exits, the peer goes away or done is closed, then closes conn.
func (s *Session) Run(done <-chan struct{}, conn *Conn, grant Grant, keyPath string, cols, rows uint16) error {
	home, err := os.MkdirTemp("", "yak-terminal-home-")
	if err != nil {
		log.Printf("termbridge: thread %d: cannot create session directory: %v", grant.ThreadID, err)
		conn.Close(1011, "internal error")
		return errors.New("cannot create session directory")
	}
	defer os.RemoveAll(home)

	bin := s.SSHBin
	if bin == "" {
		bin = "ssh"
	}
	path := s.Path
	if path == "" {
		path = os.Getenv("PATH")
	}

	cmd := exec.Command(bin, SSHArgs(keyPath, filepath.Join(home, "known_hosts"), grant.Address)...)
	cmd.Env = []string{"PATH=" + path, "HOME=" + home, "TERM=xterm-256color"}

	master, err := StartWithPTY(cmd, cols, rows)
	if err != nil {
		log.Printf("termbridge: thread %d: cannot start ssh: %v", grant.ThreadID, err)
		conn.Close(1011, "cannot start ssh")
		return errors.New("cannot start ssh")
	}

	killGroup := func() { _ = syscall.Kill(-cmd.Process.Pid, syscall.SIGKILL) }
	defer killGroup()

	exited := make(chan int, 1)
	go func() {
		_ = cmd.Wait()
		exited <- cmd.ProcessState.ExitCode()
	}()

	pumpDone := make(chan struct{})
	go func() {
		defer close(pumpDone)
		buffer := make([]byte, 16*1024)
		for {
			n, err := master.Read(buffer)
			if n > 0 {
				if conn.WriteBinary(buffer[:n]) != nil {
					return
				}
			}
			if err != nil {
				return
			}
		}
	}()

	readDone := make(chan int, 1)
	go func() { readDone <- s.readLoop(conn, master) }()

	select {
	case code := <-exited:
		select {
		case <-pumpDone:
		case <-time.After(2 * time.Second):
		}
		_ = conn.WriteText([]byte(fmt.Sprintf(`{"type":"exit","code":%d}`, code)))
		_ = conn.Close(1000, "exit")
	case closeCode := <-readDone:
		if closeCode == 0 {
			closeCode = 1000
		}
		_ = conn.Close(closeCode, "")
	case <-done:
		code := 1001
		if s.DoneCloseCode != nil {
			code = s.DoneCloseCode()
		}
		_ = conn.Close(code, "going away")
	}

	killGroup()
	master.Close()
	<-exited

	return nil
}

// readLoop returns a close code to send, or zero when the connection is already closed.
func (s *Session) readLoop(conn *Conn, master io.Writer) int {
	for {
		started := time.Now()
		if s.IdleTimeout > 0 {
			conn.SetReadDeadline(started.Add(s.IdleTimeout))
		}

		op, payload, err := conn.ReadMessage()
		if err != nil {
			if s.IdleTimeout > 0 && time.Since(started) >= s.IdleTimeout {
				return 4408
			}

			return 0
		}

		if op == OpBinary {
			if _, err := master.Write(payload); err != nil {
				return 0
			}
			continue
		}

		var message controlMessage
		if json.Unmarshal(payload, &message) != nil {
			return 1003
		}

		switch message.Type {
		case "ping":
		case "resize":
			if message.Cols < minDimension || message.Cols > maxDimension || message.Rows < minDimension || message.Rows > maxDimension {
				return 1003
			}
			if file, ok := master.(*os.File); ok {
				_ = SetSize(file, uint16(message.Cols), uint16(message.Rows))
			}
		default:
			return 1003
		}
	}
}
