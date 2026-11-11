package session

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
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

// ManifestFile is one session file in the down manifest.
type ManifestFile = api.SessionFile

// MemoryManifestFile is one auto-memory file in the down manifest.
type MemoryManifestFile = api.MemoryFile

// DownloadPlan describes one session id to bring down. Memory is only passed for the active session.
type DownloadPlan struct {
	SessionID  string
	ProjectDir string
	Files      []ManifestFile
	LastUp     []ManifestFile
	Memory     []MemoryManifestFile
	// MemoryBaseline maps memory/<name> to the sha256 of the previous completed teleport's copy. A missing
	// entry means the local copy cannot be proven unedited.
	MemoryBaseline map[string]string
	Now            time.Time
}

// WrittenFile is a verified file, reported back to Yak when the teleport completes.
type WrittenFile struct {
	SessionID string
	RelPath   string
	SHA256    string
}

// DownloadResult says what changed on this computer.
type DownloadResult struct {
	Written         []WrittenFile
	LocalBackup     string
	MemoryConflicts []string
	MemoryBackups   []string
}

type pending struct {
	apiSession string
	rel        string
	target     string
	tmp        string
	sha        string
	isMemory   bool
}

// Download fetches every manifest file into temp files, verifies length and hash, and only then moves them
// into place. Any failure leaves the project folder as it was (apart from empty directories).
func Download(ctx context.Context, s *Store, threadID int, plan DownloadPlan) (res DownloadResult, err error) {
	if !ValidSessionID(plan.SessionID) {
		return res, fmt.Errorf("%q is not a session id", plan.SessionID)
	}
	items, err := planTargets(plan)
	if err != nil {
		return res, err
	}
	defer func() {
		for _, it := range items {
			if it.tmp != "" {
				os.Remove(it.tmp)
			}
		}
	}()

	for i := range items {
		if err := os.MkdirAll(filepath.Dir(items[i].target), 0o755); err != nil {
			return res, err
		}
		if err := checkParentInside(plan.ProjectDir, items[i].target); err != nil {
			return res, err
		}
	}
	lengths := map[string]int64{}
	for _, f := range plan.Files {
		lengths[f.RelPath] = f.Length
	}
	for _, f := range plan.Memory {
		lengths[f.RelPath] = f.Length
	}
	for i := range items {
		it := &items[i]
		tmp, err := fetchVerified(ctx, s, threadID, it, lengths[it.rel])
		if tmp != "" {
			it.tmp = tmp
		}
		if err != nil {
			return res, fmt.Errorf("%s: %w", it.rel, err)
		}
	}

	date := plan.Now.Format("2006-01-02")
	lastUp := map[string]string{}
	for _, f := range plan.LastUp {
		lastUp[f.RelPath] = f.SHA256
	}
	type move struct{ from, to string }
	var backups, installs []move
	for i := range items {
		it := &items[i]
		res.Written = append(res.Written, WrittenFile{it.apiSession, it.rel, it.sha})
		localSHA, exists, err := hashFile(it.target)
		if err != nil {
			return res, err
		}
		switch {
		case !exists:
			installs = append(installs, move{it.tmp, it.target})
		case localSHA == it.sha:
			// Already identical; nothing to write.
		case it.isMemory && localSHA != plan.MemoryBaseline[it.rel]:
			dest := freeName(it.target, ".yak-"+date)
			installs = append(installs, move{it.tmp, dest})
			res.MemoryConflicts = append(res.MemoryConflicts, dest)
		case it.isMemory:
			dest := freeName(it.target, ".local-"+date)
			backups = append(backups, move{it.target, dest})
			installs = append(installs, move{it.tmp, it.target})
			res.MemoryBackups = append(res.MemoryBackups, dest)
		case it.rel == plan.SessionID+".jsonl" && localSHA != lastUp[it.rel]:
			dest := freeName(it.target, ".local-"+date)
			backups = append(backups, move{it.target, dest})
			installs = append(installs, move{it.tmp, it.target})
			res.LocalBackup = dest
		default:
			installs = append(installs, move{it.tmp, it.target})
		}
	}
	for _, m := range backups {
		if err := os.Rename(m.from, m.to); err != nil {
			return res, err
		}
	}
	for _, m := range installs {
		if err := os.Rename(m.from, m.to); err != nil {
			return res, err
		}
		for i := range items {
			if items[i].tmp == m.from {
				items[i].tmp = ""
			}
		}
	}
	return res, nil
}

// planTargets validates every manifest path before anything is read or written.
func planTargets(plan DownloadPlan) ([]pending, error) {
	root, err := filepath.Abs(plan.ProjectDir)
	if err != nil {
		return nil, err
	}
	seen := map[string]bool{}
	var items []pending
	add := func(rel, apiSession, sha string, isMemory bool) error {
		if err := ValidateRelPath(rel); err != nil {
			return err
		}
		inSession := rel == plan.SessionID+".jsonl" || strings.HasPrefix(rel, plan.SessionID+"/")
		if isMemory && !strings.HasPrefix(rel, "memory/") || !isMemory && !inSession {
			return fmt.Errorf("unexpected session path %q", rel)
		}
		if seen[rel] {
			return fmt.Errorf("duplicate session path %q", rel)
		}
		seen[rel] = true
		target := filepath.Join(root, filepath.FromSlash(rel))
		if r, err := filepath.Rel(root, target); err != nil || r == ".." || strings.HasPrefix(r, ".."+string(filepath.Separator)) || filepath.IsAbs(r) {
			return fmt.Errorf("unsafe session path %q", rel)
		}
		items = append(items, pending{apiSession: apiSession, rel: rel, target: target, sha: sha, isMemory: isMemory})
		return nil
	}
	for _, f := range plan.Files {
		if err := add(f.RelPath, plan.SessionID, f.SHA256, false); err != nil {
			return nil, err
		}
	}
	for _, f := range plan.Memory {
		if err := add(f.RelPath, memorySession, f.SHA256, true); err != nil {
			return nil, err
		}
	}
	return items, nil
}

// checkParentInside rejects a target whose directory resolves outside the project folder through a symlink.
func checkParentInside(projectDir, target string) error {
	root, err := filepath.EvalSymlinks(projectDir)
	if err != nil {
		return err
	}
	parent, err := filepath.EvalSymlinks(filepath.Dir(target))
	if err != nil {
		return err
	}
	if parent != root && !strings.HasPrefix(parent, root+string(filepath.Separator)) {
		return fmt.Errorf("%s resolves outside the project folder", target)
	}
	if info, err := os.Lstat(target); err == nil && info.Mode()&fs.ModeSymlink != 0 {
		return fmt.Errorf("%s is a symlink", target)
	}
	return nil
}

// fetchVerified reads the whole file (resuming with an offset after short reads) into a temp file beside the
// target and checks length and sha256. The temp path is returned even on error so the caller removes it.
func fetchVerified(ctx context.Context, s *Store, threadID int, it *pending, length int64) (string, error) {
	tmp, err := os.CreateTemp(filepath.Dir(it.target), ".yak-tmp-*")
	if err != nil {
		return "", err
	}
	name := tmp.Name()
	defer tmp.Close()
	h := sha256.New()
	var got int64
	for got < length {
		data, err := s.Read(ctx, threadID, it.apiSession, it.rel, got)
		if err != nil {
			return name, err
		}
		if len(data) == 0 {
			return name, fmt.Errorf("short read: got %d of %d bytes", got, length)
		}
		if got+int64(len(data)) > length {
			return name, fmt.Errorf("read more than the manifest length %d", length)
		}
		h.Write(data)
		if _, err := tmp.Write(data); err != nil {
			return name, err
		}
		got += int64(len(data))
	}
	if sum := hex.EncodeToString(h.Sum(nil)); sum != it.sha {
		return name, errors.New("sha256 does not match the manifest")
	}
	return name, tmp.Close()
}

func hashFile(path string) (sum string, exists bool, err error) {
	f, err := os.Open(path)
	if errors.Is(err, fs.ErrNotExist) {
		return "", false, nil
	}
	if err != nil {
		return "", false, err
	}
	defer f.Close()
	h := sha256.New()
	if _, err := io.Copy(h, f); err != nil {
		return "", false, err
	}
	return hex.EncodeToString(h.Sum(nil)), true, nil
}

// freeName returns path with suffix added before the extension (name.suffix.ext), and -2, -3 when taken.
func freeName(path, suffix string) string {
	ext := filepath.Ext(path)
	stem := strings.TrimSuffix(path, ext)
	candidate := stem + suffix + ext
	for n := 2; ; n++ {
		if _, err := os.Lstat(candidate); errors.Is(err, fs.ErrNotExist) {
			return candidate
		}
		candidate = fmt.Sprintf("%s%s-%d%s", stem, suffix, n, ext)
	}
}
