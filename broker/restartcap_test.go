package main

import (
	"testing"
	"time"
)

func fakeCap(limit int, window time.Duration, clock *time.Time) *restartCap {
	c := newRestartCap()
	c.limit, c.window, c.now = limit, window, func() time.Time { return *clock }
	return c
}

func TestRestartCapAllowsUpToLimit(t *testing.T) {
	now := time.Unix(0, 0)
	c := fakeCap(2, time.Minute, &now)
	if !c.allow("a") || !c.allow("a") || c.allow("a") {
		t.Fatal("expected two allowed then refused")
	}
}

func TestRestartCapResetsAfterWindow(t *testing.T) {
	now := time.Unix(0, 0)
	c := fakeCap(1, time.Minute, &now)
	c.allow("a")
	now = now.Add(time.Minute)
	if !c.allow("a") {
		t.Fatal("cap did not reset")
	}
}

func TestCapIsPerChannel(t *testing.T) {
	now := time.Unix(0, 0)
	c := fakeCap(1, time.Minute, &now)
	c.allow("a")
	if !c.allow("b") {
		t.Fatal("channel b was capped by a")
	}
}
