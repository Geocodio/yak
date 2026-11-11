package fakeyak

import (
	"bytes"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"sort"
	"strconv"
	"strings"
)

const (
	chunkCap = 1 << 20
	putCap   = 8 << 20
)

type fileKey struct {
	thread    int
	sessionID string
	relPath   string
}

type openTeleport struct {
	deviceID  int
	direction string
	state     string
}

type sessionState struct {
	files     map[fileKey][]byte
	teleports map[int]openTeleport // by thread id

	failNextStatus int
	dropNext       bool
}

// OpenTeleport registers a non-terminal teleport so the session endpoints authorize the device.
// direction is "up" or "down"; state is open, preparing or ready.
func (s *Server) OpenTeleport(threadID, deviceID int, direction, state string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.sessions.teleports == nil {
		s.sessions.teleports = map[int]openTeleport{}
	}
	s.sessions.teleports[threadID] = openTeleport{deviceID, direction, state}
}

// CloseTeleport removes the teleport registered for a thread.
func (s *Server) CloseTeleport(threadID int) {
	s.mu.Lock()
	defer s.mu.Unlock()
	delete(s.sessions.teleports, threadID)
}

// SeedSessionFile stores bytes as if a teleport had uploaded them.
func (s *Server) SeedSessionFile(threadID int, sessionID, relPath string, content []byte) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.sessions.files == nil {
		s.sessions.files = map[fileKey][]byte{}
	}
	s.sessions.files[fileKey{threadID, sessionID, relPath}] = append([]byte(nil), content...)
}

// SessionFile returns the stored bytes of a file, or nil when it does not exist.
func (s *Server) SessionFile(threadID int, sessionID, relPath string) []byte {
	s.mu.Lock()
	defer s.mu.Unlock()
	return append([]byte(nil), s.sessions.files[fileKey{threadID, sessionID, relPath}]...)
}

// FailNextAppend makes the next append answer with status and a server_error envelope, storing nothing.
func (s *Server) FailNextAppend(status int) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.sessions.failNextStatus = status
}

// DropResponseOnce stores the next append and then closes the connection without answering.
func (s *Server) DropResponseOnce() {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.sessions.dropNext = true
}

func modeOf(relPath string) string {
	switch {
	case strings.HasSuffix(relPath, ".jsonl"):
		return "append"
	case strings.Contains(relPath, "/tool-results/"):
		return "immutable"
	default:
		return "whole"
	}
}

func validRelPath(rel string) bool {
	if rel == "" || strings.HasPrefix(rel, "/") || strings.ContainsAny(rel, "\\\x00") {
		return false
	}
	for _, part := range strings.Split(rel, "/") {
		if part == "" || part == ".." || part == "." {
			return false
		}
	}
	return true
}

func hexSum(b []byte) string {
	sum := sha256.Sum256(b)
	return hex.EncodeToString(sum[:])
}

func (s *Server) sessionRoute(w http.ResponseWriter, r *http.Request, device *Device, p string) {
	rest := strings.TrimPrefix(p, "/threads/")
	idText, call, _ := strings.Cut(rest, "/session/")
	threadID, err := strconv.Atoi(idText)
	if err != nil {
		writeErr(w, 404, "thread_not_found", "That thread was not found.", nil)
		return
	}
	if _, ok := s.Threads[threadID]; !ok {
		writeErr(w, 404, "thread_not_found", "That thread was not found.", nil)
		return
	}
	write := (r.Method == "POST" || r.Method == "PUT")
	tp, ok := s.sessions.teleports[threadID]
	allowed := ok && tp.deviceID == device.ID
	if allowed && write {
		allowed = tp.direction == "up" && tp.state == "open"
	}
	if allowed && !write {
		allowed = tp.state == "open" || tp.state == "preparing" || tp.state == "ready"
	}
	if !allowed {
		writeErr(w, 409, "no_open_teleport", "There is no open teleport on this thread for this computer.", nil)
		return
	}
	if s.sessions.files == nil {
		s.sessions.files = map[fileKey][]byte{}
	}
	q := r.URL.Query()
	switch {
	case r.Method == "GET" && call == "length":
		if !s.checkPath(w, q.Get("session_id"), q.Get("rel_path")) {
			return
		}
		writeJSON(w, 200, map[string]int64{"length": int64(len(s.sessions.files[fileKey{threadID, q.Get("session_id"), q.Get("rel_path")}]))})
	case r.Method == "GET" && call == "files":
		s.listFiles(w, threadID, q.Get("session_id"))
	case r.Method == "GET" && call == "read":
		s.readFile(w, threadID, q.Get("session_id"), q.Get("rel_path"), q.Get("offset"))
	case r.Method == "GET" && call == "tree":
		s.tree(w, threadID, q.Get("session_id"))
	case r.Method == "POST" && call == "append":
		s.append(w, r, threadID)
	case r.Method == "POST" && call == "checkpoint":
		s.checkpoint(w, r, threadID)
	case r.Method == "PUT" && call == "file":
		s.putFile(w, r, threadID)
	default:
		writeErr(w, 404, "not_found", "No such route in the fake server.", nil)
	}
}

func (s *Server) checkPath(w http.ResponseWriter, sessionID, rel string) bool {
	if sessionID == "" || !validRelPath(rel) {
		writeErr(w, 422, "validation_failed", "Invalid session_id or rel_path.", nil)
		return false
	}
	return true
}

func (s *Server) listFiles(w http.ResponseWriter, threadID int, sessionID string) {
	type entry struct {
		RelPath string `json:"rel_path"`
		Mode    string `json:"mode"`
		Length  int64  `json:"length"`
		SHA256  string `json:"sha256"`
	}
	entries := []entry{}
	for k, v := range s.sessions.files {
		if k.thread == threadID && k.sessionID == sessionID {
			entries = append(entries, entry{k.relPath, modeOf(k.relPath), int64(len(v)), hexSum(v)})
		}
	}
	sort.Slice(entries, func(i, j int) bool { return entries[i].RelPath < entries[j].RelPath })
	writeJSON(w, 200, map[string]any{"files": entries})
}

func (s *Server) readFile(w http.ResponseWriter, threadID int, sessionID, rel, offsetText string) {
	if !s.checkPath(w, sessionID, rel) {
		return
	}
	data, ok := s.sessions.files[fileKey{threadID, sessionID, rel}]
	if !ok {
		writeErr(w, 404, "file_not_found", "That session file does not exist.", nil)
		return
	}
	offset, _ := strconv.ParseInt(offsetText, 10, 64)
	if offset < 0 || offset > int64(len(data)) {
		offset = int64(len(data))
	}
	w.Header().Set("Content-Type", "application/octet-stream")
	_, _ = w.Write(data[offset:])
}

func (s *Server) tree(w http.ResponseWriter, threadID int, sessionID string) {
	type entry struct {
		UUID       string  `json:"uuid"`
		ParentUUID *string `json:"parent_uuid"`
		RelPath    string  `json:"rel_path"`
		Offset     int64   `json:"offset"`
	}
	entries := []entry{}
	for k, v := range s.sessions.files {
		if k.thread != threadID || k.sessionID != sessionID || !strings.HasSuffix(k.relPath, ".jsonl") {
			continue
		}
		var offset int64
		for _, line := range bytes.SplitAfter(v, []byte("\n")) {
			var row struct {
				UUID       string  `json:"uuid"`
				ParentUUID *string `json:"parentUuid"`
			}
			if json.Unmarshal(line, &row) == nil && row.UUID != "" {
				entries = append(entries, entry{row.UUID, row.ParentUUID, k.relPath, offset})
			}
			offset += int64(len(line))
		}
	}
	writeJSON(w, 200, map[string]any{"entries": entries})
}

type appendBody struct {
	SessionID   string `json:"session_id"`
	RelPath     string `json:"rel_path"`
	Offset      int64  `json:"offset"`
	Chunk       string `json:"chunk"`
	ChunkSHA256 string `json:"chunk_sha256"`
	TailSHA256  string `json:"tail_sha256"`
}

func (s *Server) append(w http.ResponseWriter, r *http.Request, threadID int) {
	if status := s.sessions.failNextStatus; status != 0 {
		s.sessions.failNextStatus = 0
		writeErr(w, status, "server_error", "Injected failure.", nil)
		return
	}
	var b appendBody
	if json.NewDecoder(r.Body).Decode(&b) != nil {
		writeErr(w, 422, "validation_failed", "Invalid body.", nil)
		return
	}
	if !s.checkPath(w, b.SessionID, b.RelPath) {
		return
	}
	chunk, err := base64.StdEncoding.DecodeString(b.Chunk)
	if err != nil || modeOf(b.RelPath) != "append" || b.Offset < 0 {
		writeErr(w, 422, "validation_failed", "Invalid chunk.", nil)
		return
	}
	if len(chunk) > chunkCap {
		writeErr(w, 413, "payload_too_large", "Chunk is over 1 MiB.", nil)
		return
	}
	if hexSum(chunk) != b.ChunkSHA256 {
		writeErr(w, 422, "validation_failed", "chunk_sha256 does not match the chunk.", nil)
		return
	}
	key := fileKey{threadID, b.SessionID, b.RelPath}
	stored := s.sessions.files[key]
	length := int64(len(stored))
	conflict := func(reason string) {
		writeJSON(w, 409, map[string]any{"error": "session_conflict", "message": "Session file conflict.", "reason": reason, "length": length})
	}
	if b.Offset > length {
		conflict("gap")
		return
	}
	tailStart := b.Offset - 256
	if tailStart < 0 {
		tailStart = 0
	}
	if hexSum(stored[tailStart:b.Offset]) != b.TailSHA256 {
		conflict("diverged")
		return
	}
	overlap := length - b.Offset
	if overlap > int64(len(chunk)) {
		overlap = int64(len(chunk))
	}
	if !bytes.Equal(stored[b.Offset:b.Offset+overlap], chunk[:overlap]) {
		conflict("diverged")
		return
	}
	fresh := chunk[overlap:]
	if i := bytes.LastIndexByte(fresh, '\n'); i >= 0 {
		s.sessions.files[key] = append(stored, fresh[:i+1]...)
	}
	length = int64(len(s.sessions.files[key]))
	if s.sessions.dropNext {
		s.sessions.dropNext = false
		if hj, ok := w.(http.Hijacker); ok {
			if conn, _, err := hj.Hijack(); err == nil {
				_ = conn.Close()
				return
			}
		}
	}
	writeJSON(w, 200, map[string]int64{"length": length})
}

func (s *Server) checkpoint(w http.ResponseWriter, r *http.Request, threadID int) {
	var b struct {
		SessionID string `json:"session_id"`
		RelPath   string `json:"rel_path"`
		SHA256    string `json:"sha256"`
	}
	if json.NewDecoder(r.Body).Decode(&b) != nil || !s.checkPath(w, b.SessionID, b.RelPath) {
		return
	}
	data := s.sessions.files[fileKey{threadID, b.SessionID, b.RelPath}]
	if hexSum(data) == b.SHA256 {
		writeJSON(w, 200, map[string]any{"matched": true, "length": len(data)})
		return
	}
	writeJSON(w, 409, map[string]any{"error": "session_conflict", "message": "Checkpoint does not match.", "matched": false, "length": len(data)})
}

func (s *Server) putFile(w http.ResponseWriter, r *http.Request, threadID int) {
	var b struct {
		SessionID string `json:"session_id"`
		RelPath   string `json:"rel_path"`
		Content   string `json:"content"`
	}
	if json.NewDecoder(r.Body).Decode(&b) != nil || !s.checkPath(w, b.SessionID, b.RelPath) {
		return
	}
	content, err := base64.StdEncoding.DecodeString(b.Content)
	if err != nil || modeOf(b.RelPath) == "append" {
		writeErr(w, 422, "validation_failed", "Invalid content.", nil)
		return
	}
	if len(content) > putCap {
		writeErr(w, 413, "payload_too_large", "File is over 8 MiB.", nil)
		return
	}
	key := fileKey{threadID, b.SessionID, b.RelPath}
	if _, exists := s.sessions.files[key]; exists && modeOf(b.RelPath) == "immutable" {
		writeJSON(w, 200, map[string]bool{"stored": false})
		return
	}
	s.sessions.files[key] = content
	writeJSON(w, 200, map[string]bool{"stored": true})
}
