// Package gitx runs the real git binary and answers the few questions the teleport code asks of a checkout.
package gitx

import (
	"bytes"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

// Repo is a checkout directory. Env entries (KEY=VALUE) are appended to the process environment, which tests
// use to isolate git from the user's own configuration.
type Repo struct {
	Dir string
	Env []string
}

// exitCode returns the process exit code behind err, or -1 when err is not an exit status.
func exitCode(err error) int {
	var ee *exec.ExitError
	if errors.As(err, &ee) {
		return ee.ExitCode()
	}
	return -1
}

// RunBytes runs git in the repo directory and returns stdout untouched.
func (r Repo) RunBytes(args ...string) ([]byte, error) {
	cmd := exec.Command("git", append([]string{"-C", r.Dir}, args...)...)
	cmd.Env = append(append(os.Environ(), "GIT_TERMINAL_PROMPT=0", "GIT_OPTIONAL_LOCKS=0"), r.Env...)
	var stdout, stderr bytes.Buffer
	cmd.Stdout, cmd.Stderr = &stdout, &stderr
	if err := cmd.Run(); err != nil {
		return stdout.Bytes(), fmt.Errorf("git %s: %w: %s", strings.Join(args, " "), err, strings.TrimSpace(stderr.String()))
	}
	return stdout.Bytes(), nil
}

// Run runs git and returns stdout without the trailing newline.
func (r Repo) Run(args ...string) (string, error) {
	out, err := r.RunBytes(args...)
	return strings.TrimRight(string(out), "\r\n"), err
}

// Remotes returns every fetch URL.
func (r Repo) Remotes() ([]string, error) {
	out, err := r.Run("remote", "-v")
	if err != nil {
		return nil, err
	}
	var urls []string
	for _, line := range strings.Split(out, "\n") {
		if !strings.HasSuffix(line, "(fetch)") {
			continue
		}
		if f := strings.Fields(line); len(f) >= 2 {
			urls = append(urls, f[1])
		}
	}
	return urls, nil
}

// Toplevel returns the root of the working tree.
func (r Repo) Toplevel() (string, error) { return r.Run("rev-parse", "--show-toplevel") }

// CurrentBranch returns the checked-out branch, or detached=true with an empty name.
func (r Repo) CurrentBranch() (branch string, detached bool, err error) {
	out, err := r.Run("symbolic-ref", "-q", "--short", "HEAD")
	if err != nil {
		if exitCode(errors.Unwrap(err)) == 1 {
			return "", true, nil
		}
		return "", false, err
	}
	return out, false, nil
}

// Head returns the full SHA of HEAD.
func (r Repo) Head() (string, error) { return r.Run("rev-parse", "HEAD") }

// DefaultBranch returns the remote's default branch from the local remote-tracking refs (no network).
func (r Repo) DefaultBranch(remote string) (string, error) {
	prefix := "refs/remotes/" + remote + "/"
	if out, err := r.Run("symbolic-ref", "-q", prefix+"HEAD"); err == nil {
		return strings.TrimPrefix(out, prefix), nil
	}
	for _, guess := range []string{"main", "master"} {
		if _, err := r.Run("rev-parse", "--verify", "-q", prefix+guess); err == nil {
			return guess, nil
		}
	}
	return "", fmt.Errorf("cannot tell the default branch of remote %q", remote)
}

// Config reads one config value as the checkout sees it.
func (r Repo) Config(key string) (string, bool) {
	out, err := r.Run("config", "--get", key)
	return out, err == nil
}

// IsAncestor reports whether a is an ancestor of b (or equal).
func (r Repo) IsAncestor(a, b string) (bool, error) {
	_, err := r.Run("merge-base", "--is-ancestor", a, b)
	if err == nil {
		return true, nil
	}
	if exitCode(errors.Unwrap(err)) == 1 {
		return false, nil
	}
	return false, err
}

// InProgressOperation names an unfinished operation: rebase, merge, cherry-pick, revert, bisect, or "".
func (r Repo) InProgressOperation() (string, error) {
	for _, m := range []struct{ op, gitPath string }{
		{"rebase", "rebase-merge"}, {"rebase", "rebase-apply"}, {"merge", "MERGE_HEAD"},
		{"cherry-pick", "CHERRY_PICK_HEAD"}, {"revert", "REVERT_HEAD"}, {"bisect", "BISECT_LOG"},
	} {
		p, err := r.Run("rev-parse", "--git-path", m.gitPath)
		if err != nil {
			return "", err
		}
		if !filepath.IsAbs(p) {
			p = filepath.Join(r.Dir, p)
		}
		if _, err := os.Stat(p); err == nil {
			return m.op, nil
		}
	}
	return "", nil
}
