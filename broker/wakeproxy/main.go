// Command wakeproxy binds one loopback listener per sandbox, wakes the sandbox on connect and splices to its sshd.
package main

import (
	"context"
	"log"
	"net"
	"os"
	"os/signal"
	"strings"
	"syscall"
	"time"
)

func main() {
	token, err := os.ReadFile(os.Getenv("YAK_SSH_TOKEN_FILE"))
	if err != nil {
		log.Fatalf("wakeproxy: cannot read token file: %v", err)
	}

	waitTimeout := 90 * time.Second
	if raw := os.Getenv("YAK_WAKE_TIMEOUT"); raw != "" {
		parsed, err := time.ParseDuration(raw)
		if err != nil {
			log.Fatalf("wakeproxy: bad YAK_WAKE_TIMEOUT: %v", err)
		}
		waitTimeout = parsed
	}

	maxSession := 12 * time.Hour
	if raw := os.Getenv("YAK_WAKE_MAX_SESSION"); raw != "" {
		parsed, err := time.ParseDuration(raw)
		if err != nil {
			log.Fatalf("wakeproxy: bad YAK_WAKE_MAX_SESSION: %v", err)
		}
		maxSession = parsed
	}

	gateway, bridge, err := net.ParseCIDR(os.Getenv("YAK_INCUS_BRIDGE"))
	if err != nil {
		log.Fatalf("wakeproxy: bad or missing YAK_INCUS_BRIDGE: %v", err)
	}

	client := &HTTPYakClient{BaseURL: os.Getenv("YAK_SSH_URL"), Token: strings.TrimSpace(string(token))}
	handler := &Handler{
		Client:        client,
		Resolver:      IncusResolver{Bridge: bridge, Gateway: gateway},
		Dial:          func(address string) (net.Conn, error) { return net.DialTimeout("tcp", address, 2*time.Second) },
		WaitTimeout:   waitTimeout,
		PollEvery:     2 * time.Second,
		PortTimeout:   20 * time.Second,
		ActivityEvery: 60 * time.Second,
		MaxSession:    maxSession,
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	reconciler := &Reconciler{
		Client: client,
		Listen: func(address string) (net.Listener, error) { return net.Listen("tcp", address) },
		OnConn: func(connContext context.Context, address string, conn net.Conn) {
			handler.Handle(connContext, address, conn)
		},
	}
	defer reconciler.Close()

	ticker := time.NewTicker(5 * time.Second)
	defer ticker.Stop()

	for {
		if err := reconciler.Sync(ctx); err != nil {
			log.Printf("wakeproxy: sync: %v", err)
		}
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
	}
}
