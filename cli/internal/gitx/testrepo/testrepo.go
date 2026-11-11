// Package testrepo builds real git repositories in temp directories for tests.
package testrepo

import (
	"bytes"
	"encoding/hex"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
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
// can hold names such as a case-only pair, a .git component or an NFD name. Trees are written raw. It returns
// the commit SHA.
func (r *Repo) Tip(entries []Entry, parent string) string {
	r.t.Helper()
	root := &node{children: map[string]*node{}}
	for _, e := range entries {
		n := root
		parts := strings.Split(e.Path, "/")
		for _, part := range parts[:len(parts)-1] {
			c, ok := n.children[part]
			if !ok {
				c = &node{children: map[string]*node{}}
				n.children[part] = c
			}
			n = c
		}
		mode := e.Mode
		if mode == "" {
			mode = "100644"
		}
		sha := e.Content
		if mode != "160000" {
			sha = r.hashObject("blob", []byte(e.Content))
		}
		n.children[parts[len(parts)-1]] = &node{mode: mode, sha: sha}
	}
	tree := r.writeTree(root)
	args := []string{"commit-tree", tree, "-m", "tip"}
	if parent != "" {
		args = append(args, "-p", parent)
	}
	return r.Git(args...)
}

type node struct {
	mode, sha string
	children  map[string]*node
}

func (r *Repo) hashObject(kind string, content []byte) string {
	r.t.Helper()
	cmd := exec.Command("git", "-C", r.Dir, "hash-object", "-w", "--literally", "-t", kind, "--stdin")
	cmd.Env = append(os.Environ(), r.Env...)
	cmd.Stdin = bytes.NewReader(content)
	out, err := cmd.Output()
	if err != nil {
		r.t.Fatal(err)
	}
	return strings.TrimSpace(string(out))
}

func (r *Repo) writeTree(n *node) string {
	r.t.Helper()
	names := make([]string, 0, len(n.children))
	for name := range n.children {
		names = append(names, name)
	}
	sortKey := func(name string) string {
		if n.children[name].children != nil {
			return name + "/"
		}
		return name
	}
	sort.Slice(names, func(i, j int) bool { return sortKey(names[i]) < sortKey(names[j]) })
	var buf bytes.Buffer
	for _, name := range names {
		c := n.children[name]
		mode, sha := c.mode, c.sha
		if c.children != nil {
			mode, sha = "40000", r.writeTree(c)
		}
		raw, err := hex.DecodeString(sha)
		if err != nil {
			r.t.Fatal(err)
		}
		buf.WriteString(mode + " " + name)
		buf.WriteByte(0)
		buf.Write(raw)
	}
	return r.hashObject("tree", buf.Bytes())
}
