package main

import (
	"context"
	"io"
	"net"
	"time"
)

// Handler serves one connection to a sandbox's wake address.
// Plain text lines written before the splice are legal ahead of the SSH version string (RFC 4253 section 4.2).
type Handler struct {
	Client        YakClient
	Resolver      ContainerResolver
	Dial          func(address string) (net.Conn, error)
	WaitTimeout   time.Duration // how long a sandbox may stay in "waiting"
	PollEvery     time.Duration // wake re-ask interval
	PortTimeout   time.Duration // how long to wait for port 22 once ready
	ActivityEvery time.Duration // touch interval while spliced
}

func (h *Handler) Handle(ctx context.Context, address string, client net.Conn) {
	defer client.Close()

	h.waitUntilReady(ctx, address, client)
}

func say(conn net.Conn, message string) {
	_, _ = io.WriteString(conn, "yak: "+message+"\r\n")
}

// waitUntilReady runs the wake loop and, on ready, the splice.
func (h *Handler) waitUntilReady(ctx context.Context, address string, client net.Conn) {
	deadline := time.Now().Add(h.WaitTimeout)
	announced := false

	for {
		reply, err := h.Client.Wake(ctx, address)
		if err != nil {
			say(client, "could not reach Yak, try again shortly")

			return
		}

		switch reply.State {
		case "ready":
			h.spliceTo(ctx, address, reply, client)

			return
		case "waiting":
			if !announced {
				say(client, "waiting for a free sandbox")
				announced = true
			}
			if time.Now().After(deadline) {
				say(client, "timed out waiting for a free sandbox, try again shortly")

				return
			}
			select {
			case <-ctx.Done():
				return
			case <-time.After(h.PollEvery):
			}
		case "destroyed":
			say(client, "this sandbox was destroyed, restore it from the thread page")

			return
		case "closed":
			say(client, "this thread is closed")

			return
		default:
			say(client, "no sandbox here")

			return
		}
	}
}

func (h *Handler) spliceTo(ctx context.Context, address string, reply WakeReply, client net.Conn) {
	if reply.Container == nil || !containerNamePattern.MatchString(*reply.Container) {
		say(client, "sandbox name was refused")

		return
	}

	ip, err := h.Resolver.Resolve(ctx, *reply.Container)
	if err != nil {
		say(client, "could not find the sandbox address")

		return
	}

	target := net.JoinHostPort(ip, "22")
	upstream := h.dialWithRetry(ctx, target)
	if upstream == nil {
		say(client, "the sandbox did not open its ssh port in time")

		return
	}
	defer upstream.Close()

	done := make(chan struct{})
	defer close(done)

	go h.reportActivity(ctx, address, done)

	finished := make(chan struct{}, 2)
	go func() { _, _ = io.Copy(upstream, client); finished <- struct{}{} }()
	go func() { _, _ = io.Copy(client, upstream); finished <- struct{}{} }()

	<-finished
	_ = client.Close()
	_ = upstream.Close()
	<-finished
}

func (h *Handler) dialWithRetry(ctx context.Context, target string) net.Conn {
	deadline := time.Now().Add(h.PortTimeout)

	for {
		if conn, err := h.Dial(target); err == nil {
			return conn
		}
		if time.Now().After(deadline) {
			return nil
		}
		select {
		case <-ctx.Done():
			return nil
		case <-time.After(h.PollEvery):
		}
	}
}

func (h *Handler) reportActivity(ctx context.Context, address string, done <-chan struct{}) {
	ticker := time.NewTicker(h.ActivityEvery)
	defer ticker.Stop()

	for {
		select {
		case <-done:
			return
		case <-ctx.Done():
			return
		case <-ticker.C:
			_ = h.Client.Activity(ctx, address)
		}
	}
}
