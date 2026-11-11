package main

import (
	"context"
	"log/slog"
	"os"
	"os/signal"
	"strings"
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
	handle, err := runProxy(ctx, logger)
	if err != nil {
		logger.Error("auth proxy not started", "error", err)
		os.Exit(1)
	}
	checker := NewFileExposureChecker(strings.Split(os.Getenv("YAK_BROKER_SHARED_CLAUDE_REFRESH_SHA256"), ","))
	if err := New(client, IncusSpawner{}, handle.Revoker, logger, WithExposureChecker(checker)).Run(ctx); err != nil {
		logger.Error("broker stopped", "error", err)
		os.Exit(1)
	}
}
