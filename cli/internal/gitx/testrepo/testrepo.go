// Package testrepo builds real git repositories in temp directories for tests.
package testrepo

import (
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"

	"github.com/geocodio/yak/cli/internal/gitx"
)

// Env isolates git from the machine's own configuration.
var Env = []string{
	"GIT_CONFIG_GLOBAL=/dev/null", "GIT_CONFIG_SYSTEM=/dev/null",
	"GIT_AUTHOR_NAME=Test", "GIT_AUTHOR_EMAIL=test@example.com",
	"GIT_COMMITTER_NAME=Test", "GIT_COMMITTER_EMAIL=test@example.com",
}

// Repo is a gitx.Repo with test helpers. Use r.Repo wherever a gitx.Repo is wanted.
type Repo struct {
	gitx.Repo
	t *testing.T
}

func wrap(t *testing.T, dir string) *Repo {
	t.Helper()
	r := &Repo{Repo: gitx.Repo{Dir: dir, Env: Env}, t: t}
	r.Git("config", "user.name", "Test")
	r.Git("config", "user.email", "test@example.com")
	r.Git("config", "commit.gpgsign", "false")
	return r
}

// New makes an empty repo on branch main.
func New(t *testing.T) *Repo {
	t.Helper()
	dir := t.TempDir()
	cmd := exec.Command("git", "init", "-q", "-b", "main", dir)
	cmd.Env = append(os.Environ(), Env...)
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("git init: %v: %s", err, out)
	}
	return wrap(t, dir)
}

// Bare makes an empty bare repo and returns its path.
func Bare(t *testing.T) string {
	t.Helper()
	dir := t.TempDir()
	cmd := exec.Command("git", "init", "-q", "--bare", "-b", "main", dir)
	cmd.Env = append(os.Environ(), Env...)
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("git init --bare: %v: %s", err, out)
	}
	return dir
}

// Clone clones src into a new temp dir.
func Clone(t *testing.T, src string) *Repo {
	t.Helper()
	dir := filepath.Join(t.TempDir(), "clone")
	cmd := exec.Command("git", "clone", "-q", src, dir)
	cmd.Env = append(os.Environ(), Env...)
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("git clone: %v: %s", err, out)
	}
	return wrap(t, dir)
}

// Write writes a file (creating directories) without staging it.
func (r *Repo) Write(path, content string) {
	r.t.Helper()
	full := filepath.Join(r.Dir, path)
	if err := os.MkdirAll(filepath.Dir(full), 0o755); err != nil {
		r.t.Fatal(err)
	}
	if err := os.WriteFile(full, []byte(content), 0o644); err != nil {
		r.t.Fatal(err)
	}
}

// Git runs git and fails the test on error.
func (r *Repo) Git(args ...string) string {
	r.t.Helper()
	out, err := r.Run(args...)
	if err != nil {
		r.t.Fatal(err)
	}
	return out
}

// GitErr runs git and returns the error instead of failing.
func (r *Repo) GitErr(args ...string) (string, error) { return r.Run(args...) }

// Commit writes the files, stages exactly those, commits, and returns the new SHA.
func (r *Repo) Commit(files map[string]string, msg string) string {
	r.t.Helper()
	for p, c := range files {
		r.Write(p, c)
		r.Git("add", "--", p)
	}
	r.Git("commit", "-q", "--allow-empty", "-m", msg)
	return r.Git("rev-parse", "HEAD")
}

// Entry is one path in a hand-built tree.
type Entry struct {
	Path    string
	Mode    string // "100644" (default), "100755", "120000" or "160000"
	Content string // blob content; a symlink target for 120000; a commit SHA for 160000
}

// Tip builds a commit holding exactly the entries, without touching the working tree or the index, so Linux
// can hold names such as a case-only pair. It returns the commit SHA.
func (r *Repo) Tip(entries []Entry, parent string) string {
	r.t.Helper()
	idx := filepath.Join(r.t.TempDir(), "index")
	env := append(append([]string{}, r.Env...), "GIT_INDEX_FILE="+idx)
	tmp := gitx.Repo{Dir: r.Dir, Env: env}
	for _, e := range entries {
		mode := e.Mode
		if mode == "" {
			mode = "100644"
		}
		sha := e.Content
		if mode != "160000" {
			cmd := exec.Command("git", "-C", r.Dir, "hash-object", "-w", "--stdin")
			cmd.Env = append(os.Environ(), r.Env...)
			cmd.Stdin = strings.NewReader(e.Content)
			out, err := cmd.Output()
			if err != nil {
				r.t.Fatal(err)
			}
			sha = strings.TrimSpace(string(out))
		}
		if _, err := tmp.Run("update-index", "--add", "--cacheinfo", mode+","+sha+","+e.Path); err != nil {
			r.t.Fatal(err)
		}
	}
	tree, err := tmp.Run("write-tree")
	if err != nil {
		r.t.Fatal(err)
	}
	args := []string{"commit-tree", tree, "-m", "tip"}
	if parent != "" {
		args = append(args, "-p", parent)
	}
	out, err := r.Run(args...)
	if err != nil {
		r.t.Fatal(err)
	}
	return out
}
