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
	"net"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/geocodio/yak/cli/internal/api"
)

const (
	// startChunk is the first append size; a timeout halves it down to minChunk, and a line longer than the
	// current chunk doubles it up to maxChunk, the largest chunk (and so the longest line) Yak accepts.
	startChunk     = 256 << 10
	minChunk       = 32 << 10
	maxChunk       = 1 << 20
	tailSize       = 256
	memorySession  = "_memory"
	maxStalls      = 6
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

	start, err := lengthRetrying(ctx, s, threadID, apiSession, rel)
	if err != nil {
		return 0, err
	}
	if start > size {
		// Yak holds more than this computer does, so the two copies have diverged.
		s.Checkpoint(ctx, threadID, apiSession, rel, mustHashPrefix(f, size))
		return 0, ErrDiverged
	}

	offset := start
	chunkLimit, chunkFloor := int64(startChunk), int64(minChunk)
	stalls, gaps := 0, 0
	for offset < size {
		n := chunkLimit
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
			stalls = 0
			if length <= offset && offset+n < size {
				// The chunk holds no complete line yet; send more of it, up to what Yak accepts.
				if chunkLimit >= maxChunk {
					return 0, fmt.Errorf("%s has a line longer than %d bytes, which Yak cannot store", rel, maxChunk)
				}
				chunkLimit = min(chunkLimit*2, maxChunk)
				chunkFloor = chunkLimit
				continue
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
		case !isUploadTransient(err):
			return 0, err
		default:
			// The attempt timed out or the response was lost; Yak may have stored the chunk, so ask where it
			// stands. Only failures that move nothing forward count towards giving up.
			if isTimeout(err) {
				chunkLimit = max(chunkLimit/2, chunkFloor)
			}
			if stalls++; stalls > maxStalls {
				return 0, err
			}
			if berr := s.Client.Backoff(ctx, stalls); berr != nil {
				return 0, berr
			}
			stored, lengthErr := lengthRetrying(ctx, s, threadID, apiSession, rel)
			if lengthErr != nil {
				if !isUploadTransient(lengthErr) {
					return 0, lengthErr
				}
				continue
			}
			if stored > offset {
				stalls = 0
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

// isUploadTransient is true for a transport failure or a gateway or throttle answer, which a later attempt can
// get past. A plain 500 and every other API error end the upload.
func isUploadTransient(err error) bool {
	e, ok := api.AsError(err)
	if !ok {
		return api.IsTransient(err)
	}
	return e.Status == 429 || e.Status == 502 || e.Status == 503 || e.Status == 504
}

func isTimeout(err error) bool {
	var ne net.Error
	return errors.Is(err, context.DeadlineExceeded) || errors.As(err, &ne) && ne.Timeout()
}

// lengthRetrying asks for the stored length, repeating after a transient failure so one bad request does not
// end an upload.
func lengthRetrying(ctx context.Context, s *Store, threadID int, sessionID, relPath string) (int64, error) {
	for attempt := 1; ; attempt++ {
		length, err := s.Length(ctx, threadID, sessionID, relPath)
		if err == nil || !isUploadTransient(err) || attempt >= maxStalls {
			return length, err
		}
		if berr := s.Client.Backoff(ctx, attempt); berr != nil {
			return 0, berr
		}
	}
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
