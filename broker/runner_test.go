package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"github.com/alicebob/miniredis/v2"
	"github.com/redis/go-redis/v9"
	"io"
	"log/slog"
	"os/exec"
	"sort"
	"strconv"
	"strings"
	"sync"
	"syscall"
	"testing"
	"time"
)

type fakeProcess struct {
	container string
	channel   string
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
	killedAll       []string
	killedRunners   []string
	killDelay       time.Duration
	killsBegun      int
	startedChannels []string
}

func (s *fakeSpawner) Start(_ context.Context, container string, _ int, _ string, channel string) (Process, error) {
	pipeReader, pipeWriter := io.Pipe()
	process := &fakeProcess{container: container, channel: channel, stdin: &lockedBuffer{}, stdoutR: pipeReader, stdoutW: pipeWriter, exit: make(chan int, 1)}
	s.mu.Lock()
	s.started = append(s.started, process)
	s.startedChannels = append(s.startedChannels, channel)
	s.mu.Unlock()
	return process, nil
}

func (s *fakeSpawner) KillAllRunners(_ context.Context, container string) error {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.killedAll = append(s.killedAll, container)
	s.endMatching(func(process *fakeProcess) bool { return process.container == container })
	return nil
}

func (s *fakeSpawner) KillRunner(_ context.Context, container, channel string) error {
	s.mu.Lock()
	delay := s.killDelay
	s.killsBegun++
	s.mu.Unlock()
	time.Sleep(delay)
	s.mu.Lock()
	defer s.mu.Unlock()
	s.killedRunners = append(s.killedRunners, container+"|"+channel)
	s.endMatching(func(process *fakeProcess) bool { return process.container == container && process.channel == channel })
	return nil
}

// endMatching ends every matching process. The caller holds s.mu.
func (s *fakeSpawner) endMatching(matches func(*fakeProcess) bool) {
	for _, process := range s.started {
		if matches(process) {
			select {
			case process.exit <- 137:
				_ = process.stdoutW.Close()
			default:
			}
		}
	}
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

func (s *fakeSpawner) killBegunCount() int {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.killsBegun
}

func (s *fakeSpawner) startCount() int {
	s.mu.Lock()
	defer s.mu.Unlock()
	return len(s.started)
}

func (s *fakeSpawner) killAllCalls() []string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]string(nil), s.killedAll...)
}

func (s *fakeSpawner) killRunnerCalls() []string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]string(nil), s.killedRunners...)
}

func TestReadLinesDropsOversizedLinesAndKeepsTheRest(t *testing.T) {
	huge := strings.Repeat("x", maxLineBytes+1)
	long := strings.Repeat("y", maxLineBytes)
	var got []string
	var dropped []int
	readLines(strings.NewReader(huge+"\n"+long+"\nshort\r\n\n"+huge+"\npartial"),
		func(line []byte) { got = append(got, string(line)) },
		func(bytes int) { dropped = append(dropped, bytes) })
	if len(got) != 3 || got[0] != long || got[1] != "short" || got[2] != "partial" {
		t.Fatalf("lines = %d entries", len(got))
	}
	if len(dropped) != 2 || dropped[0] != maxLineBytes+1 || dropped[1] != maxLineBytes+1 {
		t.Fatalf("dropped = %v", dropped)
	}
}

func TestOversizedRunnerLineBecomesABrokerLineDropped(t *testing.T) {
	h := newHarness(t)
	h.command("oneshot:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	waitFor(t, func() bool { return h.spawner.startCount() == 1 })
	process := h.spawner.process(0)
	process.Emit(strings.Repeat("x", maxLineBytes+10))
	process.Emit(`{"type":"assistant"}`)
	entries := h.out("oneshot:1", func(entries []map[string]any) bool { return countLines(entries, "assistant") == 1 })
	for _, entry := range entries {
		if line, _ := entry["line"].(string); strings.Contains(line, "line_dropped") {
			if line != `{"type":"broker.line_dropped","bytes":`+strconv.Itoa(maxLineBytes+10)+`}` || entry["origin"] != "broker" {
				t.Fatalf("entry = %v", entry)
			}
			return
		}
	}
	t.Fatal("no broker.line_dropped entry")
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
	want := []string{`1:2:{"type":"assistant","n":1}`, `1:3:{"type":"result","n":2}`}
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
		return countLines(entries, `"type":"broker.no_runner","uuid":"u9"`) == 1
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
		return countLines(entries, `"type":"broker.no_runner","call_id":"c-9"`) == 1
	})
	waitFor(t, func() bool {
		length, _ := h.redis.XLen(context.Background(), "threads:1:in").Result()
		return length == 0
	})
}

func lineTypes(entries []map[string]any) []string {
	var types []string
	for _, entry := range entries {
		var line struct {
			Type string `json:"type"`
		}
		_ = json.Unmarshal([]byte(entry["line"].(string)), &line)
		types = append(types, line.Type)
	}
	return types
}

func findEntry(entries []map[string]any, lineType string) map[string]any {
	for _, entry := range entries {
		var line struct {
			Type string `json:"type"`
		}
		_ = json.Unmarshal([]byte(entry["line"].(string)), &line)
		if line.Type == lineType {
			return entry
		}
	}
	return nil
}

func TestRunnerStartedLineAfterStartLine(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":`+startLine+`}`)
	entries := h.out("threads:1", func(entries []map[string]any) bool { return findEntry(entries, "broker.runner_started") != nil })
	started := findEntry(entries, "broker.runner_started")
	if started["origin"] != "broker" || started["line"] != `{"type":"broker.runner_started","generation":1}` {
		t.Fatalf("entry = %v", started)
	}
	if lines := h.spawner.process(0).stdin.Lines(); len(lines) != 1 || lines[0] != startLine {
		t.Fatalf("start line not written before runner_started: %v", lines)
	}
}

func TestRunnerExitedCarriesGenerationAndExitCode(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	waitFor(t, func() bool { return h.spawner.startCount() == 1 })
	h.spawner.process(0).Exit(137)
	entries := h.out("threads:1", func(entries []map[string]any) bool { return findEntry(entries, "broker.runner_exited") != nil })
	exited := findEntry(entries, "broker.runner_exited")
	if exited["origin"] != "broker" || exited["generation"] != "1" || exited["line"] != `{"type":"broker.runner_exited","exit_code":137,"generation":1}` {
		t.Fatalf("entry = %v", exited)
	}
}

type failingSpawner struct{ *fakeSpawner }

func (failingSpawner) Start(context.Context, string, int, string, string) (Process, error) {
	return nil, errors.New("no container")
}

func TestStartFailureEmitsExitedMinusOne(t *testing.T) {
	server := miniredis.RunT(t)
	client := redis.NewClient(&redis.Options{Addr: server.Addr()})
	t.Cleanup(func() { _ = client.Close() })
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	broker := New(client, failingSpawner{&fakeSpawner{}}, nil, slog.New(slog.NewTextHandler(io.Discard, nil)))
	go func() { _ = broker.Run(ctx) }()
	h := &harness{redis: client}
	time.Sleep(50 * time.Millisecond)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	entries := h.out("threads:1", func(entries []map[string]any) bool { return findEntry(entries, "broker.runner_exited") != nil })
	if got := findEntry(entries, "broker.runner_exited")["line"]; got != `{"type":"broker.runner_exited","exit_code":-1,"generation":1}` {
		t.Fatalf("line = %v", got)
	}
}

func TestBrokerRestartedOnStartup(t *testing.T) {
	server := miniredis.RunT(t)
	client := redis.NewClient(&redis.Options{Addr: server.Addr()})
	t.Cleanup(func() { _ = client.Close() })
	client.SAdd(context.Background(), "broker:channels", "threads:1")
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	broker := New(client, &fakeSpawner{}, nil, slog.New(slog.NewTextHandler(io.Discard, nil)))
	go func() { _ = broker.Run(ctx) }()
	h := &harness{redis: client}
	entries := h.out("threads:1", func(entries []map[string]any) bool { return findEntry(entries, "broker.restarted") != nil })
	if entries[0]["origin"] != "broker" || entries[0]["line"] != `{"type":"broker.restarted"}` {
		t.Fatalf("entry = %v", entries[0])
	}
}

func TestKillCommandEmitsKillDone(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	waitFor(t, func() bool { return h.spawner.startCount() == 1 })
	h.command("threads:1", `{"type":"kill"}`)
	entries := h.out("threads:1", func(entries []map[string]any) bool { return findEntry(entries, "broker.kill_done") != nil })
	if got := findEntry(entries, "broker.kill_done")["line"]; got != `{"type":"broker.kill_done","generation":1}` {
		t.Fatalf("line = %v", got)
	}
}

func TestForgedOriginInRunnerLineIsNotBrokerOrigin(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	waitFor(t, func() bool { return h.spawner.startCount() == 1 })
	forged := `{"type":"broker.runner_exited","origin":"broker"}`
	h.spawner.process(0).Emit(forged)
	entries := h.out("threads:1", func(entries []map[string]any) bool { return countLines(entries, `"origin":"broker"`) == 1 })
	for _, entry := range entries {
		if entry["line"] == forged {
			if _, present := entry["origin"]; present {
				t.Fatal("forged runner line carries an origin field")
			}
			return
		}
	}
	t.Fatal("forged line missing or altered")
}

func TestGenerationIncrementsPerStartRunner(t *testing.T) {
	h := newHarness(t)
	for range 3 {
		h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	}
	entries := h.out("threads:1", func(entries []map[string]any) bool { return countLines(entries, `"type":"broker.runner_started"`) == 3 })
	var generations []string
	for _, entry := range entries {
		if strings.Contains(entry["line"].(string), "broker.runner_started") {
			generations = append(generations, entry["generation"].(string))
		}
	}
	if strings.Join(generations, ",") != "1,2,3" {
		t.Fatalf("generations = %v", generations)
	}
}

func TestSequenceIsMonotonicAcrossGenerations(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	waitFor(t, func() bool { return h.spawner.startCount() == 1 })
	h.spawner.process(0).Emit(`{"n":1}`)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	waitFor(t, func() bool { return h.spawner.startCount() == 2 })
	h.spawner.process(1).Emit(`{"n":2}`)
	entries := h.out("threads:1", func(entries []map[string]any) bool { return countLines(entries, `"n":2`) == 1 })
	for index, entry := range entries {
		if atoi(entry["sequence"].(string)) != index+1 && index+1 > 0 {
			// sequences must be strictly rising; late exits of generation one may interleave
			if index > 0 && atoi(entry["sequence"].(string)) <= atoi(entries[index-1]["sequence"].(string)) {
				t.Fatalf("sequence not rising at %d", index)
			}
		}
	}
}

func TestRestartedBrokerContinuesGenerationAndSequence(t *testing.T) {
	server := miniredis.RunT(t)
	client := redis.NewClient(&redis.Options{Addr: server.Addr()})
	t.Cleanup(func() { _ = client.Close() })
	logger := slog.New(slog.NewTextHandler(io.Discard, nil))
	spawner := &fakeSpawner{}
	ctx, cancel := context.WithCancel(context.Background())
	done := make(chan struct{})
	go func() { _ = New(client, spawner, nil, logger).Run(ctx); close(done) }()
	h := &harness{redis: client, spawner: spawner}
	time.Sleep(100 * time.Millisecond)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	h.out("threads:1", func(entries []map[string]any) bool { return findEntry(entries, "broker.runner_started") != nil })
	cancel()
	<-done

	ctx2, cancel2 := context.WithCancel(context.Background())
	defer cancel2()
	go func() { _ = New(client, &fakeSpawner{}, nil, logger).Run(ctx2) }()
	entries := h.out("threads:1", func(entries []map[string]any) bool { return countLines(entries, "broker.restarted") == 1 })
	last := entries[len(entries)-1]
	prev := entries[len(entries)-2]
	if last["generation"] != "1" || atoi(last["sequence"].(string)) <= atoi(prev["sequence"].(string)) {
		t.Fatalf("last = %v, previous = %v", last, prev)
	}
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	entries = h.out("threads:1", func(entries []map[string]any) bool { return countLines(entries, `"generation":2`) >= 1 })
	if got := findEntry(entries, "broker.runner_started"); got == nil {
		t.Fatal("no runner_started")
	}
}

func TestLateExitOfOldGenerationDoesNotClearNewRunner(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	waitFor(t, func() bool { return h.spawner.startCount() == 1 })
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	waitFor(t, func() bool { return h.spawner.startCount() == 2 })
	h.out("threads:1", func(entries []map[string]any) bool { return countLines(entries, `"type":"broker.runner_exited"`) >= 1 })
	h.command("threads:1", `{"type":"turn","uuid":"u1","text":"hi"}`)
	waitFor(t, func() bool { return len(h.spawner.process(1).stdin.Lines()) == 2 })
}

func TestLateExitOfOldGenerationDoesNotRevokeNewPlaceholder(t *testing.T) {
	revoker := &recordingRevoker{}
	h := newHarness(t, func(h *harness) { h.revoker = revoker })
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{"placeholder":"old"}}`)
	waitFor(t, func() bool { return h.spawner.startCount() == 1 })
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{"placeholder":"new"}}`)
	waitFor(t, func() bool { return h.spawner.startCount() == 2 })
	h.out("threads:1", func(entries []map[string]any) bool {
		return countLines(entries, `"generation":1`) >= 2 && countLines(entries, `"type":"broker.runner_exited"`) >= 1
	})
	for _, placeholder := range revoker.list() {
		if placeholder == "new" {
			t.Fatalf("old exit revoked the new placeholder: %v", revoker.list())
		}
	}
	h.spawner.process(1).Exit(0)
	waitFor(t, func() bool {
		for _, placeholder := range revoker.list() {
			if placeholder == "new" {
				return true
			}
		}
		return false
	})
}

// startDecoy starts a fake runner whose argv ends in the channel and whose child sleeps in a
// session of its own, like a Bash tool process.
func startDecoy(t *testing.T, channel, sleepMarker string) {
	t.Helper()
	decoy := exec.Command("perl", "-e", `use POSIX; if (!fork) { POSIX::setsid(); exec "sleep", "`+sleepMarker+`" } sleep 300;`, "/opt/yak-runner/runner.mjs", "--channel", channel)
	// The real runner leads its own session (setsid). The kill script kills the runner's whole
	// session, so a decoy sharing the test process's session would take the test run down with it.
	decoy.SysProcAttr = &syscall.SysProcAttr{Setsid: true}
	if err := decoy.Start(); err != nil {
		t.Fatal(err)
	}
	go func() { _ = decoy.Wait() }()
	t.Cleanup(func() { _ = exec.Command("pkill", "-KILL", "-f", "sleep "+sleepMarker).Run(); _ = decoy.Process.Kill() })
	waitFor(t, func() bool { return toolRunning(sleepMarker) })
}

func toolRunning(sleepMarker string) bool {
	return exec.Command("pgrep", "-f", "sleep "+sleepMarker).Run() == nil
}

func requirePerl(t *testing.T) {
	t.Helper()
	if _, err := exec.LookPath("perl"); err != nil {
		t.Skip("perl is required for the decoy runner")
	}
}

func TestKillAllScriptKillsToolProcessesInTheirOwnSession(t *testing.T) {
	requirePerl(t)
	startDecoy(t, "threads_1", "300.7")
	if err := exec.Command("sh", "-c", killAllRunnersScript).Run(); err != nil {
		t.Fatal(err)
	}
	waitFor(t, func() bool { return !toolRunning("300.7") })
}

func TestKillRunnerScriptKillsOnlyItsChannel(t *testing.T) {
	requirePerl(t)
	startDecoy(t, "threads_1", "300.71")
	startDecoy(t, "threads_2", "300.72")
	if err := exec.Command("sh", "-c", killRunnerScript("threads:1")).Run(); err != nil {
		t.Fatal(err)
	}
	waitFor(t, func() bool { return !toolRunning("300.71") })
	if !toolRunning("300.72") {
		t.Fatal("the other channel's tool process was killed")
	}
}

func TestKillRunnerScriptDoesNotMatchPrefixChannel(t *testing.T) {
	requirePerl(t)
	startDecoy(t, "threads_12", "300.73")
	if err := exec.Command("sh", "-c", killRunnerScript("threads:1")).Run(); err != nil {
		t.Fatal(err)
	}
	time.Sleep(500 * time.Millisecond)
	if !toolRunning("300.73") {
		t.Fatal("threads_12 was killed by the threads_1 script")
	}
}

func startInC1(h *harness, channel string) {
	h.command(channel, `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
}

func TestTwoChannelsInOneContainerHaveIndependentPipes(t *testing.T) {
	h := newHarness(t)
	startInC1(h, "threads:1")
	waitFor(t, func() bool { return h.spawner.startCount() == 1 })
	startInC1(h, "threads:2")
	waitFor(t, func() bool { return h.spawner.startCount() == 2 })
	h.command("threads:1", `{"type":"turn","uuid":"a","text":"one"}`)
	h.command("threads:2", `{"type":"turn","uuid":"b","text":"two"}`)
	waitFor(t, func() bool {
		return len(h.spawner.process(0).stdin.Lines()) == 2 && len(h.spawner.process(1).stdin.Lines()) == 2
	})
	if got := h.spawner.process(0).stdin.Lines()[1]; !strings.Contains(got, `"one"`) || strings.Contains(got, `"two"`) {
		t.Fatalf("channel 1 stdin = %s", got)
	}
	if got := h.spawner.process(1).stdin.Lines()[1]; !strings.Contains(got, `"two"`) || strings.Contains(got, `"one"`) {
		t.Fatalf("channel 2 stdin = %s", got)
	}
	h.spawner.process(0).Emit(`{"type":"assistant","n":1}`)
	h.out("threads:1", func(entries []map[string]any) bool { return countLines(entries, `"n":1`) == 1 })
	if entries, _ := h.redis.XRange(context.Background(), "threads:2:out", "-", "+").Result(); countLines(toValues(entries), `"n":1`) != 0 {
		t.Fatal("a line crossed channels")
	}
}

func toValues(entries []redis.XMessage) []map[string]any {
	values := make([]map[string]any, 0, len(entries))
	for _, entry := range entries {
		values = append(values, entry.Values)
	}
	return values
}

func TestKillOfOneChannelLeavesTheOtherRunning(t *testing.T) {
	h := newHarness(t)
	startInC1(h, "threads:1")
	waitFor(t, func() bool { return h.spawner.startCount() == 1 })
	startInC1(h, "threads:2")
	waitFor(t, func() bool { return h.spawner.startCount() == 2 })
	h.command("threads:1", `{"type":"kill"}`)
	h.out("threads:1", func(entries []map[string]any) bool { return findEntry(entries, "broker.kill_done") != nil })
	if calls := h.spawner.killRunnerCalls(); calls[len(calls)-1] != "c1|threads:1" {
		t.Fatalf("kill calls = %v", calls)
	}
	h.command("threads:2", `{"type":"turn","uuid":"b","text":"still"}`)
	waitFor(t, func() bool { return len(h.spawner.process(1).stdin.Lines()) == 2 })
	if entries, _ := h.redis.XRange(context.Background(), "threads:2:out", "-", "+").Result(); findEntry(toValues(entries), "broker.runner_exited") != nil {
		t.Fatal("channel 2 was ended")
	}
}

func TestStartRunnerOnlyKillsItsOwnChannelBeforeStarting(t *testing.T) {
	h := newHarness(t)
	startInC1(h, "threads:1")
	waitFor(t, func() bool { return h.spawner.startCount() == 1 })
	if all := h.spawner.killAllCalls(); len(all) != 0 {
		t.Fatalf("start_runner killed all runners: %v", all)
	}
	if calls := h.spawner.killRunnerCalls(); len(calls) == 0 || calls[0] != "c1|threads:1" {
		t.Fatalf("kill calls = %v", calls)
	}
}

func TestStartupKillsLeftoverRunnersOncePerContainer(t *testing.T) {
	server := miniredis.RunT(t)
	client := redis.NewClient(&redis.Options{Addr: server.Addr()})
	t.Cleanup(func() { _ = client.Close() })
	ctx := context.Background()
	client.SAdd(ctx, "broker:channels", "threads:1", "threads:2", "threads:3")
	client.HSet(ctx, "broker:containers", "threads:1", "c1", "threads:2", "c1", "threads:3", "c2")
	spawner := &fakeSpawner{}
	runCtx, cancel := context.WithCancel(ctx)
	defer cancel()
	broker := New(client, spawner, nil, slog.New(slog.NewTextHandler(io.Discard, nil)))
	go func() { _ = broker.Run(runCtx) }()
	h := &harness{redis: client}
	h.out("threads:3", func(entries []map[string]any) bool { return findEntry(entries, "broker.restarted") != nil })
	calls := spawner.killAllCalls()
	sort.Strings(calls)
	if strings.Join(calls, ",") != "c1,c2" {
		t.Fatalf("kill-all calls = %v", calls)
	}
}
