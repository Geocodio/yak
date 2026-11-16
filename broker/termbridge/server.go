package main

import (
	"context"
	"errors"
	"log"
	"net/http"
	"os"
	"strconv"
	"strings"
	"sync"
	"sync/atomic"
	"time"
)

// Limits and close codes; the same numbers live in the browser protocol file.
const (
	maxTokenLength   = 128
	closeDenied      = 4403
	closeBusy        = 4429
	closeUnavailable = 1011
)

// Server is the HTTP face of the bridge: GET /terminal/ws and GET /healthz.
type Server struct {
	Redeemer     Redeemer
	Origins      []string
	Session      Session
	KeyDir       string
	MaxPerThread int
	MaxTotal     int
	MaxDuration  time.Duration
	// Checker re-validates a running session every CheckEvery; zero CheckEvery disables it.
	Checker    Checker
	CheckEvery time.Duration
	Stop       <-chan struct{}

	mu        sync.Mutex
	total     int
	perThread map[int64]int
}

func (s *Server) originAllowed(origin string) bool {
	if origin == "" {
		return false
	}
	for _, allowed := range s.Origins {
		if origin == allowed {
			return true
		}
	}

	return false
}

func (s *Server) reserveTotal() bool {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.total >= s.MaxTotal {
		return false
	}
	s.total++

	return true
}

func (s *Server) releaseTotal() {
	s.mu.Lock()
	s.total--
	s.mu.Unlock()
}

func (s *Server) reserveThread(thread int64) bool {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.perThread == nil {
		s.perThread = map[int64]int{}
	}
	if s.perThread[thread] >= s.MaxPerThread {
		return false
	}
	s.perThread[thread]++

	return true
}

func (s *Server) releaseThread(thread int64) {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.perThread[thread]--; s.perThread[thread] <= 0 {
		delete(s.perThread, thread)
	}
}

// Handler returns the routes.
func (s *Server) Handler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("/healthz", func(w http.ResponseWriter, r *http.Request) { w.WriteHeader(http.StatusOK) })
	mux.HandleFunc("/terminal/ws", s.serveTerminal)

	return mux
}

func dimension(raw string, fallback uint16) uint16 {
	value, err := strconv.Atoi(raw)
	if err != nil || value < minDimension || value > maxDimension {
		return fallback
	}

	return uint16(value)
}

func (s *Server) serveTerminal(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return
	}
	token := r.URL.Query().Get("token")
	if token == "" || len(token) > maxTokenLength {
		http.Error(w, "bad token", http.StatusBadRequest)
		return
	}
	if !s.originAllowed(r.Header.Get("Origin")) {
		http.Error(w, "forbidden", http.StatusForbidden)
		return
	}
	conn, err := Upgrade(w, r, UpgradeOptions{CheckOrigin: s.originAllowed})
	if err != nil {
		return
	}
	defer conn.Close(1000, "")

	if !s.reserveTotal() {
		conn.Close(closeBusy, "too many sessions")
		return
	}
	defer s.releaseTotal()

	keyPath, publicLine, cleanup, err := NewEphemeralKey(s.KeyDir)
	if err != nil {
		conn.Close(closeUnavailable, "unavailable")
		return
	}
	defer cleanup()

	ctx, cancel := context.WithTimeout(r.Context(), 10*time.Second)
	grant, err := s.Redeemer.Redeem(ctx, token, publicLine, r.Header.Get("Origin"))
	cancel()
	if errors.Is(err, ErrDenied) {
		conn.Close(closeDenied, "denied")
		return
	}
	if err != nil {
		log.Printf("termbridge: redeem failed: %v", err)
		conn.Close(closeUnavailable, "unavailable")
		return
	}

	if !s.reserveThread(grant.ThreadID) {
		conn.Close(closeBusy, "too many sessions")
		return
	}
	defer s.releaseThread(grant.ThreadID)

	finished := make(chan struct{})
	defer close(finished)
	stop := make(chan struct{})
	var revoked atomic.Bool
	go func() {
		var limit <-chan time.Time
		if s.MaxDuration > 0 {
			timer := time.NewTimer(s.MaxDuration)
			defer timer.Stop()
			limit = timer.C
		}
		var recheck <-chan time.Time
		if s.Checker != nil && s.CheckEvery > 0 {
			ticker := time.NewTicker(s.CheckEvery)
			defer ticker.Stop()
			recheck = ticker.C
		}
		for {
			select {
			case <-limit:
			case <-s.Stop:
			case <-finished:
				return
			case <-recheck:
				checkContext, cancelCheck := context.WithTimeout(r.Context(), 10*time.Second)
				err := s.Checker.Check(checkContext, grant)
				cancelCheck()
				if !errors.Is(err, ErrDenied) {
					continue
				}
				revoked.Store(true)
			}
			close(stop)

			return
		}
	}()

	session := s.Session
	session.DoneCloseCode = func() int {
		if revoked.Load() {
			return closeDenied
		}

		return 1001
	}
	_ = session.Run(stop, conn, grant, keyPath,
		dimension(r.URL.Query().Get("cols"), 80), dimension(r.URL.Query().Get("rows"), 24))
}

func splitOrigins(raw string) []string {
	var origins []string
	for _, part := range strings.Split(raw, ",") {
		if part = strings.TrimSpace(part); part != "" {
			origins = append(origins, part)
		}
	}

	return origins
}

func environment(name, fallback string) string {
	if value := os.Getenv(name); value != "" {
		return value
	}

	return fallback
}
