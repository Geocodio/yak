package main

import "time"

// restartCap limits how often one channel may start a runner within a window.
// The broker calls it under no lock of its own, so callers serialize per broker.
type restartCap struct {
	limit  int
	window time.Duration
	now    func() time.Time
	starts map[string][]time.Time
}

func newRestartCap() *restartCap {
	return &restartCap{limit: 5, window: 5 * time.Minute, now: time.Now, starts: map[string][]time.Time{}}
}

// allow records the attempt and reports whether it is within the cap.
func (c *restartCap) allow(channel string) bool {
	now := c.now()
	recent := c.starts[channel][:0]
	for _, at := range c.starts[channel] {
		if now.Sub(at) < c.window {
			recent = append(recent, at)
		}
	}
	if len(recent) >= c.limit {
		c.starts[channel] = recent
		return false
	}
	c.starts[channel] = append(recent, now)
	return true
}
