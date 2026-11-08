package main

import (
	"context"
	"errors"
	"log/slog"
	"net"
	"net/http"
	"net/url"
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
	socket := os.Getenv("YAK_BROKER_CREDENTIAL_SOCKET")
	if socket == "" {
		socket = "/var/lib/yak/credentials/redeem.sock"
	}
	proxy, err := buildProxy(socket, logger)
	if err != nil {
		logger.Error("auth proxy not started", "error", err)
		os.Exit(1)
	}
	if err := New(client, IncusSpawner{}, proxy, logger).Run(ctx); err != nil {
		logger.Error("broker stopped", "error", err)
		os.Exit(1)
	}
}

// buildProxy starts the auth proxy on the Incus bridge address when YAK_BROKER_PROXY_ADDR names one.
// Real credentials only ever pass through this process's memory.
func buildProxy(socket string, logger *slog.Logger) (*AuthProxy, error) {
	listenAddress := os.Getenv("YAK_BROKER_PROXY_ADDR")
	if listenAddress == "" {
		return nil, errors.New("YAK_BROKER_PROXY_ADDR is not set")
	}
	upstreamText := os.Getenv("YAK_BROKER_PROXY_UPSTREAM")
	if upstreamText == "" {
		upstreamText = "https://api.anthropic.com"
	}
	upstream, err := url.Parse(upstreamText)
	if err != nil {
		return nil, err
	}
	host, _, err := net.SplitHostPort(listenAddress)
	if err != nil {
		return nil, err
	}
	hostCIDRs := []string{host + "/32"}
	for _, cidr := range strings.Split(os.Getenv("YAK_BROKER_PROXY_HOST_CIDRS"), ",") {
		if cidr = strings.TrimSpace(cidr); cidr != "" {
			hostCIDRs = append(hostCIDRs, cidr)
		}
	}
	listener, err := net.Listen("tcp", listenAddress)
	if err != nil {
		return nil, err
	}
	proxy, err := NewAuthProxy(SocketCredentialSource{Path: socket}, IncusSpawner{}, upstream, hostCIDRs, logger)
	if err != nil {
		return nil, err
	}
	go func() {
		if err := (&http.Server{Handler: proxy}).Serve(listener); err != nil {
			logger.Error("auth proxy stopped", "error", err)
		}
	}()
	return proxy, nil
}
