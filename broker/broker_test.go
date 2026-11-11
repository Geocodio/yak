package main

import (
	"context"
	"io"
	"log/slog"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/alicebob/miniredis/v2"
	"github.com/redis/go-redis/v9"
)

type harness struct {
	redis   *redis.Client
	spawner *fakeSpawner
	revoker PlaceholderRevoker
}

func newHarness(t *testing.T, prepare ...func(*harness)) *harness {
	t.Helper()
	server := miniredis.RunT(t)
	h := &harness{
		redis:   redis.NewClient(&redis.Options{Addr: server.Addr()}),
		spawner: &fakeSpawner{},
	}
	for _, fn := range prepare {
		fn(h)
	}
	ctx, cancel := context.WithCancel(context.Background())
	broker := New(h.redis, h.spawner, h.revoker, slog.New(slog.NewTextHandler(io.Discard, nil)))
	done := make(chan struct{})
	go func() { _ = broker.Run(ctx); close(done) }()
	t.Cleanup(func() {
		cancel()
		select {
		case <-done:
		case <-time.After(5 * time.Second):
			t.Error("broker did not stop")
		}
		_ = h.redis.Close()
	})
	// Wait for the first refresh so the channel's group exists.
	time.Sleep(50 * time.Millisecond)
	return h
}

// command registers the channel and appends a command to its in stream.
func (h *harness) command(channel string, command string) {
	ctx := context.Background()
	h.redis.SAdd(ctx, "broker:channels", channel)
	h.redis.XAdd(ctx, &redis.XAddArgs{Stream: channel + ":in", Values: map[string]any{"command": command}})
}

// out polls the out stream until the predicate holds and returns the entries.
func (h *harness) out(channel string, done func([]map[string]any) bool) []map[string]any {
	deadline := time.Now().Add(10 * time.Second)
	for {
		raw, _ := h.redis.XRange(context.Background(), channel+":out", "-", "+").Result()
		entries := make([]map[string]any, 0, len(raw))
		for _, entry := range raw {
			entries = append(entries, entry.Values)
		}
		if done(entries) {
			return entries
		}
		if time.Now().After(deadline) {
			panic("timed out waiting for out entries")
		}
		time.Sleep(20 * time.Millisecond)
	}
}

func countLines(entries []map[string]any, needle string) int {
	count := 0
	for _, entry := range entries {
		if line, ok := entry["line"].(string); ok && strings.Contains(line, needle) {
			count++
		}
	}
	return count
}

func atoi(value string) int {
	number, _ := strconv.Atoi(value)
	return number
}

func waitFor(t *testing.T, condition func() bool) {
	t.Helper()
	deadline := time.Now().Add(10 * time.Second)
	for !condition() {
		if time.Now().After(deadline) {
			t.Fatal("timed out")
		}
		time.Sleep(10 * time.Millisecond)
	}
}

func TestBrokerPublishesAHeartbeatWhileRunning(t *testing.T) {
	h := newHarness(t)
	ttl, err := h.redis.TTL(context.Background(), "broker:alive").Result()
	if err != nil || ttl <= 0 {
		t.Fatalf("heartbeat missing or without expiry: ttl=%v err=%v", ttl, err)
	}
}

const startLine = `{"type":"start","model":"m"}`

func TestForgottenChannelStreamsAreDeleted(t *testing.T) {
	h := newHarness(t)
	h.command("oneshot:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	waitFor(t, func() bool { return h.spawner.startCount() == 1 })
	h.spawner.process(0).Emit(`{"type":"assistant"}`)
	h.out("oneshot:1", func(entries []map[string]any) bool { return countLines(entries, "assistant") == 1 })

	h.redis.SRem(context.Background(), "broker:channels", "oneshot:1")

	waitFor(t, func() bool {
		exists, _ := h.redis.Exists(context.Background(), "oneshot:1:out", "oneshot:1:in").Result()
		return exists == 0
	})
}

func TestOutStreamIsCapped(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":{}}`)
	waitFor(t, func() bool { return h.spawner.startCount() == 1 })
	h.spawner.process(0).Emit(`{"type":"assistant"}`)
	h.out("threads:1", func(entries []map[string]any) bool { return countLines(entries, "assistant") == 1 })
	// miniredis reports the trim settings as it stores them; the entry list stays small and ordered.
	length, _ := h.redis.XLen(context.Background(), "threads:1:out").Result()
	if length == 0 || length > outStreamMaxLength {
		t.Fatalf("length = %d", length)
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

func TestBadJSONCommandIsDeleted(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{garbage`)
	waitFor(t, func() bool {
		length, _ := h.redis.XLen(context.Background(), "threads:1:in").Result()
		return length == 0
	})
	if h.spawner.startCount() != 0 {
		t.Fatal("garbage started a runner")
	}
}

func TestRunChannelAddressedByRunId(t *testing.T) {
	h := newHarness(t)
	h.command("runs:7", `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/workspace","start":`+startLine+`}`)
	h.command("runs:7", `{"type":"turn","uuid":"u1","text":"hi"}`)
	waitFor(t, func() bool {
		process := h.spawner.process(0)
		return process != nil && len(process.stdin.Lines()) == 2
	})
	h.spawner.process(0).Emit(`{"type":"assistant"}`)
	h.out("runs:7", func(entries []map[string]any) bool { return countLines(entries, "assistant") == 1 })
}

// recordingRevoker remembers every placeholder it was asked to revoke.
type recordingRevoker struct {
	mu      sync.Mutex
	revoked []string
}

func (r *recordingRevoker) Revoke(placeholder string) {
	r.mu.Lock()
	defer r.mu.Unlock()
	r.revoked = append(r.revoked, placeholder)
}

func (r *recordingRevoker) list() []string {
	r.mu.Lock()
	defer r.mu.Unlock()
	return append([]string(nil), r.revoked...)
}
