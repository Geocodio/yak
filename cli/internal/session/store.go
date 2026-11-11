// Package session moves Claude Code session files between this computer and Yak.
package session

import (
	"context"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/url"
	"strconv"

	"github.com/geocodio/yak/cli/internal/api"
)

// ErrDiverged means Yak's copy of an append-only file differs from the local one.
var ErrDiverged = errors.New("Yak's copy of the session differs from the one on this computer")

// ErrGap means the append offset is past the stored length; resume from Length.
type ErrGap struct{ Length int64 }

func (e ErrGap) Error() string {
	return fmt.Sprintf("session upload has a gap; resume from byte %d", e.Length)
}

// RemoteFile is one stored session file.
type RemoteFile = api.SessionFile

// AppendRequest is one chunk of an append-only file. Tail holds up to 256 bytes before Offset.
type AppendRequest struct {
	SessionID string
	RelPath   string
	Offset    int64
	Chunk     []byte
	Tail      []byte
}

// Store is the session file client for /threads/{id}/session.
type Store struct{ Client *api.Client }

func hexSum(b []byte) string {
	sum := sha256.Sum256(b)
	return hex.EncodeToString(sum[:])
}

func sessionPath(threadID int, call string) string {
	return "/threads/" + strconv.Itoa(threadID) + "/session/" + call
}

func fileQuery(sessionID, relPath string) url.Values {
	return url.Values{"session_id": {sessionID}, "rel_path": {relPath}}
}

func (s Store) getJSON(ctx context.Context, path string, q url.Values, out any) error {
	data, _, err := s.Client.Raw(ctx, http.MethodGet, path, q)
	if err != nil {
		return err
	}
	return json.Unmarshal(data, out)
}

// Length returns the stored length of a file (0 for an unknown file).
func (s Store) Length(ctx context.Context, threadID int, sessionID, relPath string) (int64, error) {
	var out struct {
		Length int64 `json:"length"`
	}
	err := s.getJSON(ctx, sessionPath(threadID, "length"), fileQuery(sessionID, relPath), &out)
	return out.Length, err
}

// Append sends one chunk. It returns the new stored length, which can be below Offset+len(Chunk).
func (s Store) Append(ctx context.Context, threadID int, f AppendRequest) (int64, error) {
	var out struct {
		Length int64 `json:"length"`
	}
	err := s.Client.Do(ctx, http.MethodPost, sessionPath(threadID, "append"), map[string]any{
		"session_id":   f.SessionID,
		"rel_path":     f.RelPath,
		"offset":       f.Offset,
		"chunk":        base64.StdEncoding.EncodeToString(f.Chunk),
		"chunk_sha256": hexSum(f.Chunk),
		"tail_sha256":  hexSum(f.Tail),
	}, &out)
	if e, ok := api.AsError(err); ok && e.Status == http.StatusConflict && e.Code == "session_conflict" {
		length, _ := e.Body["length"].(float64)
		if e.Body["reason"] == "gap" {
			return 0, ErrGap{Length: int64(length)}
		}
		return 0, ErrDiverged
	}
	return out.Length, err
}

// Checkpoint compares the whole-file hash; matched false is not an error.
func (s Store) Checkpoint(ctx context.Context, threadID int, sessionID, relPath, sha string) (bool, int64, error) {
	var out struct {
		Matched bool  `json:"matched"`
		Length  int64 `json:"length"`
	}
	err := s.Client.Do(ctx, http.MethodPost, sessionPath(threadID, "checkpoint"), map[string]string{
		"session_id": sessionID, "rel_path": relPath, "sha256": sha,
	}, &out)
	if e, ok := api.AsError(err); ok && e.Status == http.StatusConflict && e.Code == "session_conflict" {
		length, _ := e.Body["length"].(float64)
		return false, int64(length), nil
	}
	return out.Matched, out.Length, err
}

// PutFile stores a whole file; stored is false when an immutable file already exists.
func (s Store) PutFile(ctx context.Context, threadID int, sessionID, relPath string, content []byte) (bool, error) {
	var out struct {
		Stored bool `json:"stored"`
	}
	err := s.Client.Do(ctx, http.MethodPut, sessionPath(threadID, "file"), map[string]string{
		"session_id": sessionID, "rel_path": relPath, "content": base64.StdEncoding.EncodeToString(content),
	}, &out)
	return out.Stored, err
}

// Files lists the stored files of a session id.
func (s Store) Files(ctx context.Context, threadID int, sessionID string) ([]RemoteFile, error) {
	var out struct {
		Files []RemoteFile `json:"files"`
	}
	err := s.getJSON(ctx, sessionPath(threadID, "files"), url.Values{"session_id": {sessionID}}, &out)
	return out.Files, err
}

// Read returns the stored bytes of a file from offset.
func (s Store) Read(ctx context.Context, threadID int, sessionID, relPath string, offset int64) ([]byte, error) {
	q := fileQuery(sessionID, relPath)
	if offset > 0 {
		q.Set("offset", strconv.FormatInt(offset, 10))
	}
	data, _, err := s.Client.Raw(ctx, http.MethodGet, sessionPath(threadID, "read"), q)
	return data, err
}
