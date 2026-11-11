package main

import (
	"context"
	"log"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"
)

func main() {
	if os.Getenv("YAK_SSH_URL") == "" || os.Getenv("YAK_SSH_TOKEN_FILE") == "" {
		log.Fatal("termbridge: YAK_SSH_URL and YAK_SSH_TOKEN_FILE are required")
	}

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	server := &Server{
		Redeemer:     &YakClient{BaseURL: os.Getenv("YAK_SSH_URL"), TokenFile: os.Getenv("YAK_SSH_TOKEN_FILE")},
		Origins:      splitOrigins(os.Getenv("YAK_TERMBRIDGE_ORIGINS")),
		Session:      Session{SSHBin: os.Getenv("SSH_BIN"), IdleTimeout: 30 * time.Minute},
		MaxPerThread: 4,
		MaxTotal:     64,
		MaxDuration:  12 * time.Hour,
		Stop:         ctx.Done(),
	}

	httpServer := &http.Server{
		Addr:              environment("YAK_TERMBRIDGE_LISTEN", "127.0.0.1:8765"),
		Handler:           server.Handler(),
		ReadHeaderTimeout: 10 * time.Second,
	}

	go func() {
		<-ctx.Done()
		_ = httpServer.Shutdown(context.Background())
	}()

	if err := httpServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
		log.Fatalf("termbridge: %v", err)
	}
}
