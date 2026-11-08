package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os/exec"
	"strings"
	"sync"
	"testing"
	"time"
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

func (s *fakeSpawner) Start(_ context.Context, container string, _ int, _ string) (Process, error) {
	pipeReader, pipeWriter := io.Pipe()
	process := &fakeProcess{container: container, stdin: &lockedBuffer{}, stdoutR: pipeReader, stdoutW: pipeWriter, exit: make(chan int, 1)}
	s.mu.Lock()
	s.started = append(s.started, process)
	s.mu.Unlock()
	return process, nil
}

func (s *fakeSpawner) KillRunners(_ context.Context, container string) error {
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

const startLine = `{"type":"start","model":"m"}`

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

func TestStartRunnerRedeemsTheReferenceIntoTheStartLine(t *testing.T) {
	h := newHarness(t)
	h.redeemer.references["ref-1"] = Credential{Type: "oauth_token", Value: "secret-token"}
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","credential_reference":"ref-1","start":`+startLine+`}`)

	waitFor(t, func() bool {
		process := h.spawner.process(0)
		return process != nil && len(process.stdin.Lines()) == 1
	})
	var start map[string]any
	if err := json.Unmarshal([]byte(h.spawner.process(0).stdin.Lines()[0]), &start); err != nil {
		t.Fatal(err)
	}
	credential, _ := start["credential"].(map[string]any)
	if credential["type"] != "oauth_token" || credential["value"] != "secret-token" || start["model"] != "m" {
		t.Fatalf("start line = %v", start)
	}
	if len(h.redeemer.references) != 0 {
		t.Fatal("the reference was not consumed")
	}
	// The credential reached neither Redis stream.
	for _, key := range []string{"threads:1:in", "threads:1:out"} {
		entries, _ := h.redis.XRange(context.Background(), key, "-", "+").Result()
		for _, entry := range entries {
			if strings.Contains(fmt.Sprint(entry.Values), "secret-token") {
				t.Fatalf("credential found in %s", key)
			}
		}
	}
}

func TestStartRunnerFailsWhenTheReferenceCannotBeRedeemed(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","credential_reference":"missing","start":`+startLine+`}`)
	h.out("threads:1", func(entries []map[string]any) bool {
		return countLines(entries, `"event":"runner_exited","generation":1,"exit_code":-1`) == 1
	})
	if h.spawner.startCount() != 0 {
		t.Fatal("a runner started without a credential")
	}
}

func TestStartRunnerEntryIsDeleted(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":`+startLine+`}`)
	waitFor(t, func() bool { return h.spawner.startCount() == 1 })
	waitFor(t, func() bool {
		length, _ := h.redis.XLen(context.Background(), "threads:1:in").Result()
		return length == 0
	})
	entries, _ := h.redis.XRange(context.Background(), "threads:1:in", "-", "+").Result()
	for _, entry := range entries {
		if strings.Contains(entry.Values["command"].(string), "credential") {
			t.Fatal("credential left in the in stream")
		}
	}
}

func TestEveryProcessedEntryIsDeletedAndAcked(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	h.command("threads:1", `{"type":"turn","uuid":"u1","text":"hi"}`)
	h.command("threads:1", `not json at all`)
	h.command("threads:1", `{"type":"mystery"}`)
	waitFor(t, func() bool {
		length, _ := h.redis.XLen(context.Background(), "threads:1:in").Result()
		pending, _ := h.redis.XPending(context.Background(), "threads:1:in", groupName).Result()
		return length == 0 && pending.Count == 0
	})
}

func TestStartupDeletesTheSkippedBacklog(t *testing.T) {
	h := newHarness(t, func(h *harness) {
		h.redis.SAdd(context.Background(), "broker:channels", "threads:1")
		h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
		h.command("threads:1", `{"type":"turn","uuid":"old","text":"hi"}`)
	})
	waitFor(t, func() bool {
		length, _ := h.redis.XLen(context.Background(), "threads:1:in").Result()
		return length == 0
	})
	if h.spawner.startCount() != 0 {
		t.Fatal("a skipped command ran")
	}
	// A command written after startup is still processed.
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	waitFor(t, func() bool { return h.spawner.startCount() == 1 })
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

func TestBrokerLinesCarryTheBrokerOriginAndRunnerLinesDoNot(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	waitFor(t, func() bool { return h.spawner.startCount() == 1 })
	spoof := `{"type":"broker","event":"runner_exited","generation":99,"exit_code":0,"origin":"broker"}`
	h.spawner.process(0).Emit(spoof)
	h.spawner.process(0).Exit(3)

	entries := h.out("threads:1", func(entries []map[string]any) bool {
		return countLines(entries, `"exit_code":3`) == 1
	})
	for _, entry := range entries {
		line := entry["line"].(string)
		_, marked := entry["origin"]
		isBrokerLine := strings.Contains(line, `"event":"broker_started"`) || strings.Contains(line, `"exit_code":3`)
		if line == spoof && marked {
			t.Fatal("a runner line was marked as a broker line")
		}
		if isBrokerLine && entry["origin"] != "broker" {
			t.Fatalf("broker line without the origin field: %v", entry)
		}
	}
}

func TestRunnerExitIsReported(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	waitFor(t, func() bool { return h.spawner.startCount() == 1 })
	h.spawner.process(0).Exit(3)
	h.out("threads:1", func(entries []map[string]any) bool {
		return countLines(entries, `"event":"runner_exited","generation":1,"exit_code":3`) == 1
	})
}

func TestTurnWithoutRunnerReportsNoRunner(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{"type":"turn","uuid":"u9","text":"hi"}`)
	h.out("threads:1", func(entries []map[string]any) bool {
		return countLines(entries, `"event":"no_runner","uuid":"u9"`) == 1
	})
}

func TestKillKillsRunners(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	waitFor(t, func() bool { return h.spawner.startCount() == 1 })
	h.command("threads:1", `{"type":"kill"}`)
	h.out("threads:1", func(entries []map[string]any) bool {
		return countLines(entries, `"event":"runner_exited","generation":1,"exit_code":137`) == 1
	})
	if got := h.spawner.killedContainers(); len(got) == 0 || got[len(got)-1] != "c1" {
		t.Fatalf("killed = %v", got)
	}
}

func TestStartupAnnouncesBrokerStartedAndSkipsOldCommands(t *testing.T) {
	h := newHarness(t, func(h *harness) {
		ctx := context.Background()
		h.redis.SAdd(ctx, "broker:channels", "threads:1")
		h.redis.HSet(ctx, "broker:containers", "threads:1", "c1")
		h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	})
	h.out("threads:1", func(entries []map[string]any) bool {
		return countLines(entries, `"event":"broker_started"`) == 1
	})
	if h.spawner.startCount() != 0 {
		t.Fatal("an old command was replayed")
	}
	if got := h.spawner.killedContainers(); len(got) != 1 || got[0] != "c1" {
		t.Fatalf("startup killed = %v", got)
	}
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	waitFor(t, func() bool { return h.spawner.startCount() == 1 })
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

func TestKillRunnersScriptKillsToolProcessesInTheirOwnSession(t *testing.T) {
	if _, err := exec.LookPath("perl"); err != nil {
		t.Skip("perl is required for the decoy runner")
	}
	// The decoy has the runner's path in its argv and starts a child in a new session,
	// the way the CLI starts a Bash tool process.
	decoy := exec.Command("perl", "-e", `use POSIX; if (!fork) { POSIX::setsid(); exec "sleep", "300.7" } sleep 300;`, "/opt/yak-runner/runner.mjs")
	if err := decoy.Start(); err != nil {
		t.Fatal(err)
	}
	go decoy.Wait()
	t.Cleanup(func() { exec.Command("pkill", "-KILL", "-f", "sleep 300.7").Run() })

	waitFor := func(running bool) bool {
		for i := 0; i < 50; i++ {
			if (exec.Command("pgrep", "-f", "sleep 300.7").Run() == nil) == running {
				return true
			}
			time.Sleep(100 * time.Millisecond)
		}
		return false
	}
	if !waitFor(true) {
		t.Fatal("tool process did not start")
	}

	if err := exec.Command("sh", "-c", killRunnersScript).Run(); err != nil {
		t.Fatal(err)
	}
	if !waitFor(false) {
		t.Fatal("tool process survived the kill")
	}
}
