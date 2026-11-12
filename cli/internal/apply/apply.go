// Package apply moves a checkout to a thread's new tip. It only ever fast-forwards: no history is rewritten,
// nothing is combined, and no local change is discarded.
package apply

import (
	"errors"
	"fmt"
	"path/filepath"
	"strings"

	"github.com/geocodio/yak/cli/internal/gitx"
)

// Result describes where the thread's tip now lives. Mode is fast_forward, stash or worktree.
type Result struct {
	Mode          string
	Path          string
	HeadSHA       string
	Branch        string
	ConflictsLeft bool
}

// ErrLocalCommits means the local thread branch has commits that the new tip does not contain.
var ErrLocalCommits = errors.New("the local branch has commits that are not in the thread")

func branchSHA(repo gitx.Repo, branch string) (string, bool) {
	sha, err := repo.Run("rev-parse", "--verify", "-q", "refs/heads/"+branch)
	return sha, err == nil
}

// ensureFastForwardable returns the local branch SHA (if any) and refuses when it is not an ancestor of tip.
func ensureFastForwardable(repo gitx.Repo, branch, tip string) (string, bool, error) {
	local, exists := branchSHA(repo, branch)
	if !exists {
		return "", false, nil
	}
	ok, err := repo.IsAncestor(local, tip)
	if err != nil {
		return "", true, err
	}
	if !ok {
		return local, true, fmt.Errorf("%w: %s", ErrLocalCommits, branch)
	}
	return local, true, nil
}

// StashAllowed reports whether a stash-and-apply can fast-forward: false when the local thread branch has
// commits that tip does not contain.
func StashAllowed(repo gitx.Repo, branch, tip string) (bool, error) {
	_, _, err := ensureFastForwardable(repo, branch, tip)
	if errors.Is(err, ErrLocalCommits) {
		return false, nil
	}
	return err == nil, err
}

// FastForward switches to the thread branch and advances it to tip. When the branch does not exist locally it is
// created at tip, which requires createFromHead (Yak created the branch, this checkout never had it).
func FastForward(repo gitx.Repo, branch, tip string, createFromHead bool) (Result, error) {
	local, exists, err := ensureFastForwardable(repo, branch, tip)
	if err != nil {
		return Result{}, err
	}
	if !exists {
		if !createFromHead {
			return Result{}, fmt.Errorf("branch %s does not exist in this checkout", branch)
		}
		if _, err := repo.Run("switch", "-c", branch, tip); err != nil {
			return Result{}, err
		}
		return Result{Mode: "fast_forward", Path: repo.Dir, HeadSHA: tip, Branch: branch}, nil
	}
	if current, _, _ := repo.CurrentBranch(); current != branch {
		if _, err := repo.Run("switch", branch); err != nil {
			return Result{}, err
		}
	}
	if local != tip {
		// --keep moves the branch forward and refuses, changing nothing, when a local change is in the way.
		if _, err := repo.Run("reset", "--keep", tip); err != nil {
			return Result{}, err
		}
	}
	return Result{Mode: "fast_forward", Path: repo.Dir, HeadSHA: tip, Branch: branch}, nil
}

// StashAndApply stashes tracked and untracked changes, fast-forwards, and pops the stash. A pop that conflicts
// keeps the stash, leaves the markers in place and sets ConflictsLeft.
func StashAndApply(repo gitx.Repo, branch, tip string) (Result, error) {
	if _, _, err := ensureFastForwardable(repo, branch, tip); err != nil {
		return Result{}, err
	}
	before, _ := repo.Run("rev-parse", "--verify", "-q", "refs/stash")
	if _, err := repo.Run("stash", "push", "--include-untracked", "-m", "yak teleport "+branch); err != nil {
		return Result{}, err
	}
	after, _ := repo.Run("rev-parse", "--verify", "-q", "refs/stash")
	stashed := after != before

	res, err := FastForward(repo, branch, tip, true)
	if err != nil {
		if stashed {
			repo.Run("stash", "pop")
		}
		return Result{}, err
	}
	res.Mode = "stash"
	if !stashed {
		return res, nil
	}
	if _, popErr := repo.Run("stash", "pop"); popErr != nil {
		conflicted, _ := repo.Run("diff", "--name-only", "--diff-filter=U")
		if strings.TrimSpace(conflicted) == "" {
			return res, fmt.Errorf("your changes are kept in the stash named \"yak teleport %s\": %w", branch, popErr)
		}
		res.ConflictsLeft = true
	}
	return res, nil
}

// Worktree adds a git worktree next to the primary checkout at <primary>-yak-<slug> and leaves the primary
// untouched. When the primary is on the thread branch itself the worktree uses the local branch <branch>-yak.
func Worktree(repo gitx.Repo, primaryDir, slug, branch, tip string) (Result, error) {
	path := filepath.Clean(primaryDir) + "-yak-" + slug
	wtBranch := branch
	if current, _, _ := repo.CurrentBranch(); current == branch {
		wtBranch = branch + "-yak"
	}
	local, exists, err := ensureFastForwardable(repo, wtBranch, tip)
	if err != nil {
		return Result{}, err
	}
	if exists {
		if local != tip {
			if _, err := repo.Run("update-ref", "-m", "yak teleport", "refs/heads/"+wtBranch, tip, local); err != nil {
				return Result{}, err
			}
		}
		_, err = repo.Run("worktree", "add", path, wtBranch)
	} else {
		_, err = repo.Run("worktree", "add", "-b", wtBranch, path, tip)
	}
	if err != nil {
		return Result{}, err
	}
	return Result{Mode: "worktree", Path: path, HeadSHA: tip, Branch: wtBranch}, nil
}
