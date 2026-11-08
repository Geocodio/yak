package main

import (
	"context"
	"io"
	"log/slog"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/alicebob/miniredis/v2"
	"github.com/redis/go-redis/v9"
)

type harness struct {
	redis   *redis.Client
	spawner *fakeSpawner
	proxy   *AuthProxy
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
	broker := New(h.redis, h.spawner, h.proxy, slog.New(slog.NewTextHandler(io.Discard, nil)))
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
