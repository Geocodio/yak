package main

import (
	"bufio"
	"context"
	"errors"
	"io"
	"net"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

type fakeResolver struct {
	ip     string
	err    error
	called int32
}

func (f *fakeResolver) Resolve(ctx context.Context, container string) (string, error) {
	atomic.AddInt32(&f.called, 1)

	return f.ip, f.err
}

func str(value string) *string { return &value }

func newHandler(yak *fakeYak, upstream net.Conn) (*Handler, *fakeResolver) {
	resolver := &fakeResolver{ip: "10.1.2.3"}

	return &Handler{
		Client:   yak,
		Resolver: resolver,
		Dial: func(address string) (net.Conn, error) {
			if upstream == nil {
				return nil, errors.New("refused")
			}

			return upstream, nil
		},
		WaitTimeout:   200 * time.Millisecond,
		PollEvery:     5 * time.Millisecond,
		PortTimeout:   50 * time.Millisecond,
		ActivityEvery: 10 * time.Millisecond,
	}, resolver
}

func readAll(t *testing.T, conn net.Conn) string {
	t.Helper()
	_ = conn.SetReadDeadline(time.Now().Add(2 * time.Second))
	data, _ := io.ReadAll(conn)

	return string(data)
}

func TestReadySplicesBothWays(t *testing.T) {
	yak := &fakeYak{wake: func(string) WakeReply { return WakeReply{State: "ready", Container: str("yak-thread-1")} }}
	clientSide, handlerSide := net.Pipe()
	upstreamHandlerSide, upstreamServerSide := net.Pipe()
	handler, _ := newHandler(yak, upstreamHandlerSide)

	finished := make(chan struct{})
	go func() { handler.Handle(context.Background(), "127.16.0.1", handlerSide); close(finished) }()

	go func() { _, _ = clientSide.Write([]byte("SSH-2.0-client\r\n")) }()
	line, _ := bufio.NewReader(upstreamServerSide).ReadString('\n')
	if line != "SSH-2.0-client\r\n" {
		t.Fatalf("upstream got %q", line)
	}

	go func() { _, _ = upstreamServerSide.Write([]byte("SSH-2.0-server\r\n")) }()
	line, _ = bufio.NewReader(clientSide).ReadString('\n')
	if line != "SSH-2.0-server\r\n" {
		t.Fatalf("client got %q", line)
	}

	_ = clientSide.Close()
	select {
	case <-finished:
	case <-time.After(2 * time.Second):
		t.Fatal("splice did not end when the client closed")
	}
}

func TestWaitingThenReadyAnnouncesOnce(t *testing.T) {
	var calls int32
	yak := &fakeYak{wake: func(string) WakeReply {
		if atomic.AddInt32(&calls, 1) < 3 {
			return WakeReply{State: "waiting"}
		}

		return WakeReply{State: "ready", Container: str("yak-thread-1")}
	}}
	clientSide, handlerSide := net.Pipe()
	upstreamHandlerSide, upstreamServerSide := net.Pipe()
	handler, _ := newHandler(yak, upstreamHandlerSide)

	go handler.Handle(context.Background(), "127.16.0.1", handlerSide)

	reader := bufio.NewReader(clientSide)
	line, _ := reader.ReadString('\n')
	if line != "yak: waiting for a free sandbox\r\n" {
		t.Fatalf("first line %q", line)
	}

	go func() { _, _ = upstreamServerSide.Write([]byte("SSH-2.0-server\r\n")) }()
	line, _ = reader.ReadString('\n')
	if line != "SSH-2.0-server\r\n" {
		t.Fatalf("second line %q (waiting must be announced once)", line)
	}
	_ = clientSide.Close()
}

func TestWaitTimeoutWritesMessageAndCloses(t *testing.T) {
	yak := &fakeYak{wake: func(string) WakeReply { return WakeReply{State: "waiting"} }}
	clientSide, handlerSide := net.Pipe()
	handler, _ := newHandler(yak, nil)

	go handler.Handle(context.Background(), "127.16.0.1", handlerSide)

	out := readAll(t, clientSide)
	if !strings.Contains(out, "waiting for a free sandbox") || !strings.Contains(out, "timed out") {
		t.Fatalf("output %q", out)
	}
}

func TestTerminalStatesWriteMessageAndClose(t *testing.T) {
	cases := map[string]string{
		"destroyed":  "yak: this sandbox was destroyed, restore it from the thread page\r\n",
		"closed":     "yak: this thread is closed\r\n",
		"no_sandbox": "yak: no sandbox here\r\n",
	}

	for state, want := range cases {
		yak := &fakeYak{wake: func(string) WakeReply { return WakeReply{State: state} }}
		clientSide, handlerSide := net.Pipe()
		handler, resolver := newHandler(yak, nil)

		go handler.Handle(context.Background(), "127.16.0.1", handlerSide)

		if got := readAll(t, clientSide); got != want {
			t.Fatalf("%s: got %q", state, got)
		}
		if resolver.called != 0 {
			t.Fatalf("%s: resolver called", state)
		}
	}
}

func TestInvalidContainerNameIsRefused(t *testing.T) {
	for _, name := range []string{"../etc", "Yak", "a b", "-lead", strings.Repeat("a", 64), ""} {
		yak := &fakeYak{wake: func(string) WakeReply { return WakeReply{State: "ready", Container: str(name)} }}
		clientSide, handlerSide := net.Pipe()
		handler, resolver := newHandler(yak, nil)

		go handler.Handle(context.Background(), "127.16.0.1", handlerSide)

		if got := readAll(t, clientSide); !strings.Contains(got, "refused") {
			t.Fatalf("%q: got %q", name, got)
		}
		if resolver.called != 0 {
			t.Fatalf("%q: resolver called", name)
		}
	}
}

func TestPortNeverOpensClosesWithMessage(t *testing.T) {
	yak := &fakeYak{wake: func(string) WakeReply { return WakeReply{State: "ready", Container: str("yak-thread-1")} }}
	clientSide, handlerSide := net.Pipe()
	handler, _ := newHandler(yak, nil)

	go handler.Handle(context.Background(), "127.16.0.1", handlerSide)

	if got := readAll(t, clientSide); !strings.Contains(got, "did not open its ssh port") {
		t.Fatalf("output %q", got)
	}
}

func TestSpliceEndsWhenUpstreamCloses(t *testing.T) {
	yak := &fakeYak{wake: func(string) WakeReply { return WakeReply{State: "ready", Container: str("yak-thread-1")} }}
	clientSide, handlerSide := net.Pipe()
	upstreamHandlerSide, upstreamServerSide := net.Pipe()
	handler, _ := newHandler(yak, upstreamHandlerSide)

	finished := make(chan struct{})
	go func() { handler.Handle(context.Background(), "127.16.0.1", handlerSide); close(finished) }()

	time.Sleep(20 * time.Millisecond)
	_ = upstreamServerSide.Close()

	select {
	case <-finished:
	case <-time.After(2 * time.Second):
		t.Fatal("splice did not end when upstream closed")
	}
	_ = clientSide.Close()
}

func TestActivityFiresWhileSpliced(t *testing.T) {
	yak := &fakeYak{
		activity: make(chan string, 16),
		wake:     func(string) WakeReply { return WakeReply{State: "ready", Container: str("yak-thread-1")} },
	}
	clientSide, handlerSide := net.Pipe()
	upstreamHandlerSide, _ := net.Pipe()
	handler, _ := newHandler(yak, upstreamHandlerSide)

	go handler.Handle(context.Background(), "127.16.0.7", handlerSide)
	defer clientSide.Close()

	select {
	case address := <-yak.activity:
		if address != "127.16.0.7" {
			t.Fatalf("activity address %q", address)
		}
	case <-time.After(2 * time.Second):
		t.Fatal("no activity ping")
	}
}

func TestIncusResolverRejectsBadNames(t *testing.T) {
	if _, err := (IncusResolver{}).Resolve(context.Background(), "../x"); err == nil {
		t.Fatal("expected refusal")
	}
}
