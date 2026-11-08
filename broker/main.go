package main

import (
	"context"
	"log/slog"
	"os"
	"os/signal"
	"syscall"

	"github.com/redis/go-redis/v9"
)

func main() {
	logger := slog.New(slog.NewJSONHandler(os.Stderr, nil))
	address := os.Getenv("YAK_BROKER_REDIS_ADDR")
	if address == "" {
		address = "127.0.0.1:6379"
	}
	client := redis.NewClient(&redis.Options{Addr: address, Password: os.Getenv("YAK_BROKER_REDIS_PASSWORD")})

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGTERM, syscall.SIGINT)
	defer stop()
	if err := New(client, IncusSpawner{}, logger).Run(ctx); err != nil {
		logger.Error("broker stopped", "error", err)
		os.Exit(1)
	}
}
