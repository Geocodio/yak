package main

import (
	"context"
	"net"
	"sort"
	"strings"
	"sync"
	"testing"
	"time"
)

type fakeYak struct {
	mutex       sync.Mutex
	listeners   []Listener
	wake        func(address string) WakeReply
	activity    chan string
	activityErr error
	wakeCalls   []string
}

func (f *fakeYak) Listeners(ctx context.Context) ([]Listener, error) {
	f.mutex.Lock()
	defer f.mutex.Unlock()

	return append([]Listener(nil), f.listeners...), nil
}

func (f *fakeYak) Wake(ctx context.Context, address string) (WakeReply, error) {
	f.mutex.Lock()
	f.wakeCalls = append(f.wakeCalls, address)
	f.mutex.Unlock()

	return f.wake(address), nil
}

func (f *fakeYak) Activity(ctx context.Context, address string) error {
	if f.activity != nil {
		f.activity <- address
	}

	return f.activityErr
}

func (f *fakeYak) set(addresses ...string) {
	f.mutex.Lock()
	defer f.mutex.Unlock()

	f.listeners = nil
	for _, address := range addresses {
		f.listeners = append(f.listeners, Listener{Address: address, Port: 22})
	}
}

func newReconciler(yak *fakeYak, bound *[]string) *Reconciler {
	return &Reconciler{
		Client: yak,
		Listen: func(address string) (net.Listener, error) {
			*bound = append(*bound, address)

			return net.Listen("tcp", "127.0.0.1:0")
		},
	}
}

func sorted(values []string) string {
	sort.Strings(values)

	return strings.Join(values, ",")
}

func TestSyncAddsRemovesAndKeeps(t *testing.T) {
	yak := &fakeYak{}
	var bound []string
	reconciler := newReconciler(yak, &bound)
	defer reconciler.Close()

	yak.set("127.16.0.1", "127.16.0.2")
	if err := reconciler.Sync(context.Background()); err != nil {
		t.Fatal(err)
	}
	if got := sorted(reconciler.Addresses()); got != "127.16.0.1,127.16.0.2" {
		t.Fatalf("after add: %s", got)
	}

	yak.set("127.16.0.2", "127.16.0.3")
	if err := reconciler.Sync(context.Background()); err != nil {
		t.Fatal(err)
	}
	if got := sorted(reconciler.Addresses()); got != "127.16.0.2,127.16.0.3" {
		t.Fatalf("after change: %s", got)
	}

	// .1 and .2 on first sync, only .3 on the second: .2 was kept, not rebound.
	if got := sorted(bound); got != "127.16.0.1:22,127.16.0.2:22,127.16.0.3:22" {
		t.Fatalf("bound: %s", got)
	}
}

func TestSyncRefusesAddressesOutsideLoopback(t *testing.T) {
	yak := &fakeYak{}
	var bound []string
	reconciler := newReconciler(yak, &bound)
	defer reconciler.Close()

	yak.set("10.0.0.5", "128.0.0.1", "::1", "not-an-ip", "127.16.0.9")
	if err := reconciler.Sync(context.Background()); err != nil {
		t.Fatal(err)
	}

	if got := sorted(reconciler.Addresses()); got != "127.16.0.9" {
		t.Fatalf("addresses: %s", got)
	}
}

func TestSyncPassesAcceptedConnectionsTheListenerAddress(t *testing.T) {
	yak := &fakeYak{}
	var bound []string
	reconciler := newReconciler(yak, &bound)
	defer reconciler.Close()

	seen := make(chan string, 1)
	reconciler.OnConn = func(ctx context.Context, address string, conn net.Conn) {
		seen <- address
		_ = conn.Close()
	}

	yak.set("127.16.0.4")
	if err := reconciler.Sync(context.Background()); err != nil {
		t.Fatal(err)
	}

	reconciler.mutex.Lock()
	target := reconciler.listeners["127.16.0.4"].Addr().String()
	reconciler.mutex.Unlock()

	conn, err := net.Dial("tcp", target)
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()

	if got := <-seen; got != "127.16.0.4" {
		t.Fatalf("address: %s", got)
	}
}

func connectTo(t *testing.T, reconciler *Reconciler, address string) net.Conn {
	t.Helper()

	reconciler.mutex.Lock()
	target := reconciler.listeners[address].Addr().String()
	reconciler.mutex.Unlock()

	conn, err := net.Dial("tcp", target)
	if err != nil {
		t.Fatal(err)
	}

	return conn
}

func TestRemovedAddressCancelsActiveConnections(t *testing.T) {
	yak := &fakeYak{}
	var bound []string
	reconciler := newReconciler(yak, &bound)
	defer reconciler.Close()

	contexts := make(chan struct {
		address string
		ctx     context.Context
	}, 4)
	reconciler.OnConn = func(ctx context.Context, address string, conn net.Conn) {
		contexts <- struct {
			address string
			ctx     context.Context
		}{address, ctx}
		<-ctx.Done()
		_ = conn.Close()
	}

	yak.set("127.16.0.1", "127.16.0.2")
	if err := reconciler.Sync(context.Background()); err != nil {
		t.Fatal(err)
	}

	for _, address := range []string{"127.16.0.1", "127.16.0.2"} {
		conn := connectTo(t, reconciler, address)
		defer conn.Close()
	}

	seen := map[string]context.Context{}
	for i := 0; i < 2; i++ {
		entry := <-contexts
		seen[entry.address] = entry.ctx
	}

	yak.set("127.16.0.2")
	if err := reconciler.Sync(context.Background()); err != nil {
		t.Fatal(err)
	}

	select {
	case <-seen["127.16.0.1"].Done():
	case <-time.After(time.Second):
		t.Fatal("removed address context was not cancelled")
	}
	if seen["127.16.0.2"].Err() != nil {
		t.Fatal("kept address context was cancelled")
	}
}

func TestCloseCancelsEverything(t *testing.T) {
	yak := &fakeYak{}
	var bound []string
	reconciler := newReconciler(yak, &bound)

	got := make(chan context.Context, 1)
	reconciler.OnConn = func(ctx context.Context, address string, conn net.Conn) {
		got <- ctx
		<-ctx.Done()
		_ = conn.Close()
	}

	yak.set("127.16.0.1")
	if err := reconciler.Sync(context.Background()); err != nil {
		t.Fatal(err)
	}
	conn := connectTo(t, reconciler, "127.16.0.1")
	defer conn.Close()
	ctx := <-got

	reconciler.Close()

	select {
	case <-ctx.Done():
	case <-time.After(time.Second):
		t.Fatal("Close did not cancel the connection context")
	}
}
