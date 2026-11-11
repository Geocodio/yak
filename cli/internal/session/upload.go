package session

import (
	"bufio"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/geocodio/yak/cli/internal/api"
)

const (
	chunkSize      = 1 << 20
	tailSize       = 256
	memorySession  = "_memory"
	maxRetries     = 3
	maxGapResumes  = 5
	subagentsDir   = "subagents"
	toolResultsDir = "tool-results"
)

// ErrCheckpointMismatch means Yak's copy of a file does not hash to the local one after upload.
var ErrCheckpointMismatch = errors.New("Yak's copy of a session file does not match the one on this computer")

// UploadResult counts what was sent.
type UploadResult struct {
	Files int
	Bytes int64
}

// Upload sends the session folder to Yak: the transcript, subagents, tool results, then changed memory files.
// projectDir is the Claude project folder that holds <sessionID>.jsonl. Memory files are sent only when
// modified after memoryChangedSince; the zero time means the start of the session.
func Upload(ctx context.Context, s *Store, threadID int, sessionID, projectDir string, memoryChangedSince time.Time, progress func(string)) (UploadResult, error) {
	var res UploadResult
	if progress == nil {
		progress = func(string) {}
	}
	if !ValidSessionID(sessionID) {
		return res, fmt.Errorf("%q is not a session id", sessionID)
	}
	main := sessionID + ".jsonl"
	if _, err := os.Stat(filepath.Join(projectDir, main)); err != nil {
		return res, err
	}
	if memoryChangedSince.IsZero() {
		memoryChangedSince = sessionStart(filepath.Join(projectDir, main))
	}

	order, err := uploadOrder(projectDir, sessionID, memoryChangedSince)
	if err != nil {
		return res, err
	}
	for _, rel := range order {
		path := filepath.Join(projectDir, filepath.FromSlash(rel))
		apiSession := sessionID
		if strings.HasPrefix(rel, "memory/") {
			apiSession = memorySession
		}
		sent, err := uploadOne(ctx, s, threadID, apiSession, rel, path)
		if err != nil {
			return res, err
		}
		if sent > 0 {
			res.Files++
			res.Bytes += sent
			progress(rel)
		}
	}
	return res, nil
}

// uploadOrder lists relative paths in protocol order. Symlinks and anything failing ValidateRelPath are skipped.
func uploadOrder(projectDir, sessionID string, memorySince time.Time) ([]string, error) {
	order := []string{sessionID + ".jsonl"}
	collect := func(dir string, accept func(rel string, info fs.FileInfo) bool) error {
		root := filepath.Join(projectDir, filepath.FromSlash(dir))
		err := filepath.WalkDir(root, func(path string, d fs.DirEntry, err error) error {
			if err != nil {
				if errors.Is(err, fs.ErrNotExist) {
					return nil
				}
				return err
			}
			if d.Type()&fs.ModeSymlink != 0 {
				return nil
			}
			if d.IsDir() || !d.Type().IsRegular() {
				return nil
			}
			rel, err := filepath.Rel(projectDir, path)
			if err != nil {
				return nil
			}
			rel = filepath.ToSlash(rel)
			info, err := d.Info()
			if err != nil || ValidateRelPath(rel) != nil || !accept(rel, info) {
				return nil
			}
			order = append(order, rel)
			return nil
		})
		return err
	}
	all := func(string, fs.FileInfo) bool { return true }
	if err := collect(sessionID+"/"+subagentsDir, all); err != nil {
		return nil, err
	}
	if err := collect(sessionID+"/"+toolResultsDir, all); err != nil {
		return nil, err
	}
	if err := collect("memory", func(_ string, info fs.FileInfo) bool { return info.ModTime().After(memorySince) }); err != nil {
		return nil, err
	}
	return order, nil
}

// sessionStart is the first timestamp in the transcript, else the transcript's modification time.
func sessionStart(path string) time.Time {
	f, err := os.Open(path)
	if err == nil {
		defer f.Close()
		line, _ := bufio.NewReader(f).ReadBytes('\n')
		var row struct {
			Timestamp string `json:"timestamp"`
		}
		if json.Unmarshal(line, &row) == nil {
			if t, err := time.Parse(time.RFC3339, row.Timestamp); err == nil {
				return t
			}
		}
	}
	if info, err := os.Stat(path); err == nil {
		return info.ModTime()
	}
	return time.Time{}
}

func uploadOne(ctx context.Context, s *Store, threadID int, apiSession, rel, path string) (int64, error) {
	if strings.HasSuffix(rel, ".jsonl") {
		return uploadAppendOnly(ctx, s, threadID, apiSession, rel, path)
	}
	content, err := os.ReadFile(path)
	if err != nil {
		return 0, err
	}
	stored, err := s.PutFile(ctx, threadID, apiSession, rel, content)
	if err != nil || !stored {
		return 0, err
	}
	return int64(len(content)), nil
}

func uploadAppendOnly(ctx context.Context, s *Store, threadID int, apiSession, rel, path string) (int64, error) {
	f, err := os.Open(path)
	if err != nil {
		return 0, err
	}
	defer f.Close()
	info, err := f.Stat()
	if err != nil {
		return 0, err
	}
	size := info.Size()

	start, err := s.Length(ctx, threadID, apiSession, rel)
	if err != nil {
		return 0, err
	}
	if start > size {
		// Yak holds more than this computer does, so the two copies have diverged.
		s.Checkpoint(ctx, threadID, apiSession, rel, mustHashPrefix(f, size))
		return 0, ErrDiverged
	}

	offset := start
	lostResponses, gaps := 0, 0
	for offset < size {
		n := int64(chunkSize)
		if size-offset < n {
			n = size - offset
		}
		chunk := make([]byte, n)
		if _, err := f.ReadAt(chunk, offset); err != nil {
			return 0, err
		}
		tailStart := offset - tailSize
		if tailStart < 0 {
			tailStart = 0
		}
		tail := make([]byte, offset-tailStart)
		if _, err := f.ReadAt(tail, tailStart); err != nil && len(tail) > 0 {
			return 0, err
		}

		length, err := s.Append(ctx, threadID, AppendRequest{SessionID: apiSession, RelPath: rel, Offset: offset, Chunk: chunk, Tail: tail})
		var gap ErrGap
		switch {
		case err == nil:
			lostResponses = 0
			if length <= offset && offset+n < size {
				return 0, fmt.Errorf("%s has a line longer than %d bytes, which Yak cannot store", rel, chunkSize)
			}
			if length <= offset {
				// Only an unterminated final line is left; it is sent once the line is finished.
				offset = length
				goto done
			}
			offset = length
		case errors.Is(err, ErrDiverged):
			s.Checkpoint(ctx, threadID, apiSession, rel, mustHashPrefix(f, size))
			return 0, ErrDiverged
		case errors.As(err, &gap):
			if gaps++; gaps > maxGapResumes {
				return 0, err
			}
			offset = gap.Length
		default:
			if _, isAPI := api.AsError(err); isAPI {
				return 0, err
			}
			// The response was lost; Yak may have stored the chunk, so ask where it stands.
			if lostResponses++; lostResponses > maxRetries {
				return 0, err
			}
			stored, lengthErr := s.Length(ctx, threadID, apiSession, rel)
			if lengthErr != nil {
				return 0, lengthErr
			}
			offset = stored
		}
	}
done:
	sum, err := hashPrefix(f, offset)
	if err != nil {
		return 0, err
	}
	matched, _, err := s.Checkpoint(ctx, threadID, apiSession, rel, sum)
	if err != nil {
		return 0, err
	}
	if !matched {
		return 0, ErrCheckpointMismatch
	}
	return offset - start, nil
}

func hashPrefix(f *os.File, n int64) (string, error) {
	h := sha256.New()
	if _, err := io.Copy(h, io.NewSectionReader(f, 0, n)); err != nil {
		return "", err
	}
	return hex.EncodeToString(h.Sum(nil)), nil
}

func mustHashPrefix(f *os.File, n int64) string {
	sum, _ := hashPrefix(f, n)
	return sum
}
