package main

import (
	"bytes"
	"context"
	"encoding/json"
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
	mu              sync.Mutex
	started         []*fakeProcess
	killed          []string
	startedChannels []string
}

func (s *fakeSpawner) Start(_ context.Context, container string, _ int, _ string, channel string) (Process, error) {
	pipeReader, pipeWriter := io.Pipe()
	process := &fakeProcess{container: container, stdin: &lockedBuffer{}, stdoutR: pipeReader, stdoutW: pipeWriter, exit: make(chan int, 1)}
	s.mu.Lock()
	s.started = append(s.started, process)
	s.startedChannels = append(s.startedChannels, channel)
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

func (s *fakeSpawner) channels() []string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]string(nil), s.startedChannels...)
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

func TestStartRunnerWritesStartLineFirst(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":`+startLine+`}`)
	h.command("threads:1", `{"type":"turn","uuid":"u1","text":"hi"}`)

	waitFor(t, func() bool {
		process := h.spawner.process(0)
		return process != nil && len(process.stdin.Lines()) == 2
	})
	lines := h.spawner.process(0).stdin.Lines()
	if lines[0] != startLine {
		t.Fatalf("first stdin line = %s", lines[0])
	}
	if lines[1] != `{"type":"user","uuid":"u1","text":"hi"}` {
		t.Fatalf("second stdin line = %s", lines[1])
	}
}

func TestRunnerLinesAreCopiedUnchangedWithSequence(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	waitFor(t, func() bool { return h.spawner.startCount() == 1 })
	first := h.spawner.process(0)
	first.Emit(`{"type":"assistant","n":1}`)
	first.Emit(`{"type":"result","n":2}`)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	waitFor(t, func() bool { return h.spawner.startCount() == 2 })
	h.spawner.process(1).Emit(`{"type":"assistant","n":3}`)

	entries := h.out("threads:1", func(entries []map[string]any) bool { return countLines(entries, `"n":3`) == 1 })
	var sequences []string
	for _, entry := range entries {
		if line := entry["line"].(string); strings.Contains(line, `"n":`) {
			sequences = append(sequences, entry["generation"].(string)+":"+entry["sequence"].(string)+":"+line)
		}
	}
	want := []string{`1:1:{"type":"assistant","n":1}`, `1:2:{"type":"result","n":2}`}
	for index, expected := range want {
		if sequences[index] != expected {
			t.Fatalf("entry %d = %s, want %s", index, sequences[index], expected)
		}
	}
	last := sequences[len(sequences)-1]
	if !strings.HasPrefix(last, "2:") || !strings.HasSuffix(last, `{"type":"assistant","n":3}`) {
		t.Fatalf("generation two entry = %s", last)
	}
	var seqs []int
	for _, entry := range entries {
		seqs = append(seqs, atoi(entry["sequence"].(string)))
	}
	for index := 1; index < len(seqs); index++ {
		if seqs[index] <= seqs[index-1] {
			t.Fatalf("sequence not rising: %v", seqs)
		}
	}
}

func TestLongLinesAreNotTruncated(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	waitFor(t, func() bool { return h.spawner.startCount() == 1 })
	long := `{"type":"assistant","text":"` + strings.Repeat("x", 1<<20) + `"}`
	h.spawner.process(0).Emit(long)
	entries := h.out("threads:1", func(entries []map[string]any) bool {
		for _, entry := range entries {
			if entry["line"] == long {
				return true
			}
		}
		return false
	})
	_ = entries
}

func TestTurnWithoutRunnerReportsNoRunner(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{"type":"turn","uuid":"u9","text":"hi"}`)
	h.out("threads:1", func(entries []map[string]any) bool {
		return countLines(entries, `"event":"no_runner","uuid":"u9"`) == 1
	})
}

func TestStartRunnerPassesThePlaceholderAndNoCredential(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{"type":"start","placeholder":"yakph-abc"}}`)
	waitFor(t, func() bool {
		process := h.spawner.process(0)
		return process != nil && len(process.stdin.Lines()) == 1
	})
	var start map[string]any
	if err := json.Unmarshal([]byte(h.spawner.process(0).stdin.Lines()[0]), &start); err != nil {
		t.Fatal(err)
	}
	if start["placeholder"] != "yakph-abc" {
		t.Fatalf("start line = %v", start)
	}
	if _, present := start["credential"]; present {
		t.Fatal("a credential reached the runner")
	}
	if got := h.spawner.channels(); len(got) != 1 || got[0] != "threads:1" {
		t.Fatalf("spawner channels = %v", got)
	}
}

func TestRunnerExitRevokesThePlaceholder(t *testing.T) {
	revoker := &recordingRevoker{}
	h := newHarness(t, func(h *harness) { h.revoker = revoker })
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{"type":"start","placeholder":"yakph-abc"}}`)
	waitFor(t, func() bool { return h.spawner.startCount() == 1 })

	h.spawner.process(0).Exit(0)

	waitFor(t, func() bool { return len(revoker.list()) == 1 })
	if got := revoker.list(); got[0] != "yakph-abc" {
		t.Fatalf("revoked = %v", got)
	}
}

func TestToolResultIsWrittenToRunnerStdin(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":`+startLine+`}`)
	h.command("threads:1", `{"type":"tool_result","call_id":"c-1","is_error":true,"content":"nope"}`)
	waitFor(t, func() bool {
		process := h.spawner.process(0)
		return process != nil && len(process.stdin.Lines()) == 2
	})
	if got := h.spawner.process(0).stdin.Lines()[1]; got != `{"type":"tool_result","call_id":"c-1","is_error":true,"content":"nope"}` {
		t.Fatalf("stdin line = %s", got)
	}
}

func TestToolResultWithoutRunnerReportsNoRunner(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{"type":"tool_result","call_id":"c-9","is_error":false,"content":"x"}`)
	h.out("threads:1", func(entries []map[string]any) bool {
		return countLines(entries, `"event":"no_runner","call_id":"c-9"`) == 1
	})
	waitFor(t, func() bool {
		length, _ := h.redis.XLen(context.Background(), "threads:1:in").Result()
		return length == 0
	})
}
