// Package fakeyak is an in-memory implementation of /api/cli/v1 for tests.
package fakeyak

import (
	"bytes"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"strconv"
	"strings"
	"sync"
	"testing"
	"time"

	"github.com/geocodio/yak/cli/internal/api"
)

// Repo is a repository known to the fake Yak.
type Repo struct {
	Slug          string
	DefaultBranch string
	CloneURL      string
	// BarePath is a bare git repository standing in for GitHub; when set, teleport-up verifies pushed heads in it.
	BarePath string
}

// Thread is the contract thread object.
type Thread = api.Thread

// RequestLog is one request the server saw.
type RequestLog struct {
	Method string
	Path   string
	Query  string
	Header http.Header
	Body   []byte
}

// Server is the fake. Set the exported state before making requests; the mutex guards handler access.
type Server struct {
	mu  sync.Mutex
	srv *httptest.Server

	Repos        map[string]Repo
	Threads      map[int]*Thread
	Devices      map[int]*Device
	User         api.User
	MinProtocol  int
	SlowDownNext int
	OnRequest    func(*http.Request)
	// ReadLimit caps the bytes one session read returns, so clients must resume with an offset (0 means no cap).
	ReadLimit int

	codes    map[string]*pendingCode // by device_code
	requests []RequestLog
	nextID   int

	sessions sessionState
	tele     teleportState
	// Clock drives teleport expiry; nil means time.Now.
	Clock func() time.Time
}

// New starts the server and stops it when the test ends.
func New(t testing.TB) *Server {
	s := &Server{
		Repos:       map[string]Repo{},
		Threads:     map[int]*Thread{},
		Devices:     map[int]*Device{},
		User:        api.User{ID: 3, Name: "Mia Hansen", Email: "mia@acme.test"},
		MinProtocol: 1,
		codes:       map[string]*pendingCode{},
		nextID:      1,
	}
	s.srv = httptest.NewServer(http.HandlerFunc(s.serve))
	t.Cleanup(s.srv.Close)
	return s
}

// URL is the base URL of the server.
func (s *Server) URL() string { return s.srv.URL }

// Requests returns a copy of the request log.
func (s *Server) Requests() []RequestLog {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]RequestLog(nil), s.requests...)
}

func (s *Server) newID() int {
	id := s.nextID
	s.nextID++
	return id
}

func (s *Server) serve(w http.ResponseWriter, r *http.Request) {
	if s.OnRequest != nil {
		s.OnRequest(r)
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	body, _ := io.ReadAll(r.Body)
	r.Body = io.NopCloser(bytes.NewReader(body))
	s.requests = append(s.requests, RequestLog{r.Method, r.URL.Path, r.URL.RawQuery, r.Header.Clone(), body})

	w.Header().Set("X-Yak-Protocol", "1")
	w.Header().Set("X-Yak-Min-Protocol", strconv.Itoa(s.MinProtocol))
	client, _ := strconv.Atoi(r.Header.Get("X-Yak-Protocol"))
	if client < s.MinProtocol {
		writeErr(w, 426, "protocol_too_old", "This yak command is too old for Yak. Update the Yak plugin.", map[string]any{
			"min_protocol": s.MinProtocol, "current_protocol": 1, "client_protocol": client,
		})
		return
	}

	p := strings.TrimPrefix(r.URL.Path, "/api/cli/v1")
	switch {
	case r.Method == "POST" && p == "/device/code":
		s.deviceCode(w, r)
		return
	case r.Method == "POST" && p == "/device/token":
		s.deviceToken(w, r)
		return
	}

	device, ok := s.authenticate(w, r)
	if !ok {
		return
	}
	switch {
	case r.Method == "GET" && p == "/me":
		writeJSON(w, 200, map[string]any{
			"device": device.info(), "user": s.User,
			"protocol": map[string]int{"current": 1, "min": s.MinProtocol}, "base_url": s.srv.URL,
		})
	case r.Method == "DELETE" && p == "/device":
		device.Revoked = true
		w.WriteHeader(204)
	case r.Method == "POST" && p == "/repos/resolve":
		s.resolve(w, r)
	case strings.HasPrefix(p, "/threads/") && strings.Contains(p, "/session/"):
		s.sessionRoute(w, r, device, p)
	case strings.HasPrefix(p, "/teleports"):
		s.teleportRoute(w, r, device, p)
	case strings.HasPrefix(p, "/threads/") && (strings.HasSuffix(p, "/ssh") || strings.Contains(p, "/stack-messages")):
		s.threadExtraRoute(w, r, device, p)
	case r.Method == "GET" && strings.HasPrefix(p, "/threads/by-session/"):
		sid := strings.TrimPrefix(p, "/threads/by-session/")
		for _, t := range s.Threads {
			if t.ActiveSessionID == sid {
				writeJSON(w, 200, api.ThreadResponse{Thread: *t})
				return
			}
		}
		writeErr(w, 404, "thread_not_found", "That thread was not found.", nil)
	case r.Method == "GET" && strings.HasPrefix(p, "/threads/") && !strings.Contains(strings.TrimPrefix(p, "/threads/"), "/"):
		id, _ := strconv.Atoi(strings.TrimPrefix(p, "/threads/"))
		if t, ok := s.Threads[id]; ok {
			writeJSON(w, 200, api.ThreadResponse{Thread: *t})
			return
		}
		writeErr(w, 404, "thread_not_found", "That thread was not found.", nil)
	default:
		writeErr(w, 404, "not_found", "No such route in the fake server.", nil)
	}
}

func (s *Server) authenticate(w http.ResponseWriter, r *http.Request) (*Device, bool) {
	h := r.Header.Get("Authorization")
	if !strings.HasPrefix(h, "Bearer ") {
		writeErr(w, 401, "unauthenticated", "Sign in with yak login.", nil)
		return nil, false
	}
	token := strings.TrimPrefix(h, "Bearer ")
	for _, d := range s.Devices {
		if d.Token == token {
			if d.Revoked {
				writeErr(w, 401, "device_revoked", "This computer was removed from Yak.", nil)
				return nil, false
			}
			return d, true
		}
	}
	writeErr(w, 401, "invalid_token", "That token is not valid.", nil)
	return nil, false
}

func (s *Server) resolve(w http.ResponseWriter, r *http.Request) {
	var req api.ResolveRequest
	if json.NewDecoder(r.Body).Decode(&req) != nil || len(req.Remotes) > 20 {
		writeErr(w, 422, "validation_failed", "Send between 1 and 20 remotes.", nil)
		return
	}
	out := make([]api.ResolveItem, 0, len(req.Remotes))
	for _, remote := range req.Remotes {
		item := api.ResolveItem{Remote: remote}
		if repo, ok := s.Repos[NormalizeRemote(remote)]; ok {
			slug, branch, clone := repo.Slug, repo.DefaultBranch, repo.CloneURL
			item.OnYak, item.Repo, item.DefaultBranch, item.CloneURL = true, &slug, &branch, &clone
		}
		out = append(out, item)
	}
	writeJSON(w, 200, api.ResolveResult{Repos: out})
}

// NormalizeRemote reduces https and ssh remote URLs to an owner/name slug.
func NormalizeRemote(remote string) string {
	r := strings.TrimSuffix(strings.TrimSpace(remote), ".git")
	if i := strings.Index(r, "://"); i >= 0 {
		r = r[i+3:]
		r = r[strings.Index(r+"/", "/")+1:]
	} else if i := strings.Index(r, ":"); i >= 0 {
		r = r[i+1:]
	}
	parts := strings.Split(strings.Trim(r, "/"), "/")
	if len(parts) > 2 {
		parts = parts[len(parts)-2:]
	}
	return strings.Join(parts, "/")
}

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(v)
}

func writeErr(w http.ResponseWriter, status int, code, message string, details map[string]any) {
	body := map[string]any{"error": code, "message": message}
	if details != nil {
		body["details"] = details
	}
	writeJSON(w, status, body)
}
