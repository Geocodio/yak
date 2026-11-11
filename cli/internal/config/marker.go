package config

import (
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"time"

	"github.com/geocodio/yak/cli/internal/session"
)

// maxMarkerBytes bounds what a hook will read, so a hostile or runaway file cannot stall a prompt.
const maxMarkerBytes = 4096

// Marker says a session is owned by Yak and which thread owns it. Hooks read it without any network call.
type Marker struct {
	ThreadID         int       `json:"thread_id"`
	TeleportID       string    `json:"teleport_id"`
	Owner            string    `json:"owner"`
	ThreadURL        string    `json:"thread_url,omitempty"`
	StackMessagesURL string    `json:"stack_messages_url,omitempty"`
	BaseURL          string    `json:"base_url,omitempty"`
	WrittenAt        time.Time `json:"written_at"`
}

func sessionFile(dir, kind, sessionID, ext string) (string, error) {
	if !session.ValidSessionID(sessionID) {
		return "", fmt.Errorf("%q is not a session id", sessionID)
	}
	return filepath.Join(dir, kind, sessionID+ext), nil
}

// writePrivate writes content atomically with mode 0600 inside a 0700 folder.
func writePrivate(path string, content []byte) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return err
	}
	tmp, err := os.CreateTemp(filepath.Dir(path), ".tmp-*")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if err := tmp.Chmod(0o600); err != nil {
		tmp.Close()
		return err
	}
	if _, err := tmp.Write(content); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	return os.Rename(tmp.Name(), path)
}

// WriteMarker stores the marker at <dir>/markers/<sessionID>.json.
func WriteMarker(dir, sessionID string, m Marker) error {
	path, err := sessionFile(dir, "markers", sessionID, ".json")
	if err != nil {
		return err
	}
	b, err := json.Marshal(m)
	if err != nil {
		return err
	}
	return writePrivate(path, b)
}

// ReadMarker returns false for a missing, truncated, oversized or mistyped marker; it never errors.
func ReadMarker(dir, sessionID string) (Marker, bool) {
	var m Marker
	path, err := sessionFile(dir, "markers", sessionID, ".json")
	if err != nil {
		return m, false
	}
	f, err := os.Open(path)
	if err != nil {
		return m, false
	}
	defer f.Close()
	b, err := io.ReadAll(io.LimitReader(f, maxMarkerBytes+1))
	if err != nil || len(b) > maxMarkerBytes {
		return Marker{}, false
	}
	if json.Unmarshal(b, &m) != nil || m.ThreadID <= 0 || m.TeleportID == "" {
		return Marker{}, false
	}
	return m, true
}

// ClearMarker removes the marker; a missing marker is not an error.
func ClearMarker(dir, sessionID string) error {
	path, err := sessionFile(dir, "markers", sessionID, ".json")
	if err != nil {
		return err
	}
	if err := os.Remove(path); err != nil && !errors.Is(err, os.ErrNotExist) {
		return err
	}
	return nil
}

// WriteNote stores the environment note for the next SessionStart hook.
func WriteNote(dir, sessionID, text string) error {
	path, err := sessionFile(dir, "notes", sessionID, ".md")
	if err != nil {
		return err
	}
	return writePrivate(path, []byte(text))
}

// TakeNote reads the note and deletes it, so the hook prints it once.
func TakeNote(dir, sessionID string) (string, bool) {
	path, err := sessionFile(dir, "notes", sessionID, ".md")
	if err != nil {
		return "", false
	}
	b, err := os.ReadFile(path)
	if err != nil {
		return "", false
	}
	os.Remove(path)
	return string(b), len(b) > 0
}
