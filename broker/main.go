package main

import (
	"context"
	"log/slog"
	"os"
	"os/signal"
	"syscall"

	"github.com/redis/go-redis/v9"
)

// startProxy starts the auth proxy and returns the revoker the broker tells about ended runners.
// The proxy build replaces it from an init function; without a proxy nothing is revoked.
var startProxy = func(socketPath string, logger *slog.Logger) (PlaceholderRevoker, error) {
	return noopRevoker{}, nil
}

func main() {
	logger := slog.New(slog.NewJSONHandler(os.Stderr, nil))
	address := os.Getenv("YAK_BROKER_REDIS_ADDR")
	if address == "" {
		address = "127.0.0.1:6379"
	}
	client := redis.NewClient(&redis.Options{Addr: address, Password: os.Getenv("YAK_BROKER_REDIS_PASSWORD")})

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer stop()
	socket := os.Getenv("YAK_BROKER_CREDENTIAL_SOCKET")
	if socket == "" {
		socket = "/var/lib/yak/credentials/redeem.sock"
	}
	revoker, err := startProxy(socket, logger)
	if err != nil {
		logger.Error("auth proxy not started", "error", err)
		os.Exit(1)
	}
	if err := New(client, IncusSpawner{}, revoker, logger).Run(ctx); err != nil {
		logger.Error("broker stopped", "error", err)
		os.Exit(1)
	}
}
