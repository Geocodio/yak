package main

import (
	"bytes"
	"context"
	"io"
	"strings"
	"sync"
	"testing"
)

type fakeProcess struct {
	container string
	stdin     *lockedBuffer
	stdoutR   *io.PipeReader
	stdoutW   *io.PipeWriter
	exit      chan int
}

func (p *fakeProcess) Stdin() io.WriteCloser { return p.stdin }
func (p *fakeProcess) Stdout() io.Reader     { return p.stdoutR }
func (p *fakeProcess) Wait() (int, error)    { return <-p.exit, nil }

// Emit writes one runner line to the process's stdout.
func (p *fakeProcess) Emit(line string) { _, _ = p.stdoutW.Write([]byte(line + "\n")) }

// Exit ends the process with the given code.
func (p *fakeProcess) Exit(code int) {
	_ = p.stdoutW.Close()
	p.exit <- code
}

type lockedBuffer struct {
	mu  sync.Mutex
	buf bytes.Buffer
}

func (l *lockedBuffer) Write(data []byte) (int, error) {
	l.mu.Lock()
	defer l.mu.Unlock()
	return l.buf.Write(data)
}
func (l *lockedBuffer) Close() error { return nil }
func (l *lockedBuffer) Lines() []string {
	l.mu.Lock()
	defer l.mu.Unlock()
	text := strings.TrimSuffix(l.buf.String(), "\n")
	if text == "" {
		return nil
	}
	return strings.Split(text, "\n")
}

type fakeSpawner struct {
	mu      sync.Mutex
	started []*fakeProcess
	killed  []string
}

func (s *fakeSpawner) Start(_ context.Context, container string, _ int, _ string, _ string) (Process, error) {
	pipeReader, pipeWriter := io.Pipe()
	process := &fakeProcess{container: container, stdin: &lockedBuffer{}, stdoutR: pipeReader, stdoutW: pipeWriter, exit: make(chan int, 1)}
	s.mu.Lock()
	s.started = append(s.started, process)
	s.mu.Unlock()
	return process, nil
}

func (s *fakeSpawner) KillAllRunners(_ context.Context, container string) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.killed = append(s.killed, container)
	for _, process := range s.started {
		if process.container == container {
			select {
			case process.exit <- 137:
				_ = process.stdoutW.Close()
			default:
			}
		}
	}
	return nil
}

func (s *fakeSpawner) process(index int) *fakeProcess {
	s.mu.Lock()
	defer s.mu.Unlock()
	if index < len(s.started) {
		return s.started[index]
	}
	return nil
}

func (s *fakeSpawner) startCount() int {
	s.mu.Lock()
	defer s.mu.Unlock()
	return len(s.started)
}

func (s *fakeSpawner) killedContainers() []string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]string(nil), s.killed...)
}

func (s *fakeSpawner) KillRunner(ctx context.Context, container, _ string) error {
	return s.KillAllRunners(ctx, container)
}

func TestReadLinesHasNoSizeLimitAndEmitsFinalPartialLine(t *testing.T) {
	long := strings.Repeat("x", 1<<20)
	var got []string
	readLines(strings.NewReader(long+"\nshort\r\n\npartial"), func(line []byte) { got = append(got, string(line)) })
	if len(got) != 3 || got[0] != long || got[1] != "short" || got[2] != "partial" {
		t.Fatalf("lines = %d entries", len(got))
	}
}

func TestFirstGlobalIPv4PicksEth0NotDockerBridge(t *testing.T) {
	state := `{"network":{"docker0":{"addresses":[{"family":"inet","address":"172.17.0.1","scope":"global"}]},"eth0":{"addresses":[{"family":"inet6","address":"fd42::1","scope":"global"},{"family":"inet","address":"10.0.0.5","scope":"global"}]}}}`
	address, err := firstGlobalIPv4([]byte(state))
	if err != nil || address != "10.0.0.5" {
		t.Fatalf("address = %q, err = %v", address, err)
	}
	if _, err := firstGlobalIPv4([]byte(`{"network":{"docker0":{"addresses":[{"family":"inet","address":"172.17.0.1","scope":"global"}]}}}`)); err == nil {
		t.Fatal("a docker bridge address was accepted")
	}
}

func TestSanitizeChannel(t *testing.T) {
	for input, want := range map[string]string{"threads:12": "threads_12", "runs:a;b": "runs_a_b", "ok-Name_1": "ok-Name_1"} {
		if got := sanitizeChannel(input); got != want {
			t.Fatalf("sanitizeChannel(%q) = %q, want %q", input, got, want)
		}
	}
}
