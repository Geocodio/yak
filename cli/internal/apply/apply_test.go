package apply

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/geocodio/yak/cli/internal/gitx/testrepo"
)

// threadTip commits the files on a temporary branch off main and returns the SHA, leaving main checked out.
func threadTip(t *testing.T, r *testrepo.Repo, keepBranch string, files map[string]string) string {
	t.Helper()
	r.Git("switch", "-q", "-c", "tmp-thread")
	sha := r.Commit(files, "thread work")
	r.Git("switch", "-q", "main")
	if keepBranch != "" {
		r.Git("branch", keepBranch, sha)
	}
	r.Git("branch", "-q", "-D", "tmp-thread")
	return sha
}

func base(t *testing.T) *testrepo.Repo {
	r := testrepo.New(t)
	r.Commit(map[string]string{"f": "base\n", "g": "g\n"}, "base")
	return r
}

func TestFastForwardSwitchesAndAdvances(t *testing.T) {
	t.Parallel()
	r := base(t)
	old := r.Git("rev-parse", "HEAD")
	r.Git("branch", "thread", old)
	tip := threadTip(t, r, "", map[string]string{"f": "thread\n"})
	res, err := FastForward(r.Repo, "thread", tip, false)
	if err != nil {
		t.Fatal(err)
	}
	if res.Mode != "fast_forward" || res.HeadSHA != tip {
		t.Fatal(res)
	}
	if b, _, _ := r.CurrentBranch(); b != "thread" {
		t.Fatal(b)
	}
	if head, _ := r.Head(); head != tip {
		t.Fatal(head, tip)
	}
	if got, _ := os.ReadFile(filepath.Join(r.Dir, "f")); string(got) != "thread\n" {
		t.Fatal(string(got))
	}
}

func TestCreatesBranchFromTipWhenYakCreatedIt(t *testing.T) {
	t.Parallel()
	r := base(t)
	tip := threadTip(t, r, "", map[string]string{"new.txt": "n"})
	if _, err := FastForward(r.Repo, "yak/new", tip, false); err == nil {
		t.Fatal("must not create without createFromHead")
	}
	res, err := FastForward(r.Repo, "yak/new", tip, true)
	if err != nil {
		t.Fatal(err)
	}
	if b, _, _ := r.CurrentBranch(); b != "yak/new" || res.HeadSHA != tip {
		t.Fatal(b, res)
	}
}

func TestRefusesNonAncestorLocalBranch(t *testing.T) {
	t.Parallel()
	r := base(t)
	tip := threadTip(t, r, "", map[string]string{"f": "thread\n"})
	r.Git("switch", "-q", "-c", "thread")
	r.Commit(map[string]string{"local.txt": "mine"}, "local commit")
	headBefore, _ := r.Head()
	_, err := FastForward(r.Repo, "thread", tip, false)
	if !errors.Is(err, ErrLocalCommits) {
		t.Fatal(err)
	}
	if head, _ := r.Head(); head != headBefore {
		t.Fatal("head moved")
	}
	if ok, err := StashAllowed(r.Repo, "thread", tip); err != nil || ok {
		t.Fatal(ok, err)
	}
}

func TestStashAppliesAndPops(t *testing.T) {
	t.Parallel()
	r := base(t)
	tip := threadTip(t, r, "", map[string]string{"g": "thread g\n"})
	r.Write("f", "local edit\n")
	r.Write("untracked.txt", "u")
	res, err := StashAndApply(r.Repo, "thread", tip)
	if err != nil {
		t.Fatal(err)
	}
	if res.Mode != "stash" || res.ConflictsLeft {
		t.Fatal(res)
	}
	if got, _ := os.ReadFile(filepath.Join(r.Dir, "f")); string(got) != "local edit\n" {
		t.Fatal(string(got))
	}
	if got, _ := os.ReadFile(filepath.Join(r.Dir, "g")); string(got) != "thread g\n" {
		t.Fatal(string(got))
	}
	if _, err := os.Stat(filepath.Join(r.Dir, "untracked.txt")); err != nil {
		t.Fatal("untracked file lost")
	}
	if list := r.Git("stash", "list"); list != "" {
		t.Fatal("stash not popped:", list)
	}
	if head, _ := r.Head(); head != tip {
		t.Fatal(head)
	}
}

func TestStashPopConflictKeepsStashAndMarkers(t *testing.T) {
	t.Parallel()
	r := base(t)
	tip := threadTip(t, r, "", map[string]string{"f": "thread\n"})
	r.Write("f", "local\n")
	res, err := StashAndApply(r.Repo, "thread", tip)
	if err != nil {
		t.Fatal(err)
	}
	if !res.ConflictsLeft {
		t.Fatal(res)
	}
	if r.Git("stash", "list") == "" {
		t.Fatal("stash must be kept")
	}
	if got, _ := os.ReadFile(filepath.Join(r.Dir, "f")); !strings.Contains(string(got), "<<<<<<<") {
		t.Fatal("no markers:", string(got))
	}
}

func TestStashNotOfferedWhenBranchHasLocalCommits(t *testing.T) {
	t.Parallel()
	r := base(t)
	tip := threadTip(t, r, "", map[string]string{"g": "thread\n"})
	r.Git("switch", "-q", "-c", "thread")
	r.Commit(map[string]string{"local.txt": "mine"}, "local commit")
	r.Write("f", "dirty\n")
	if _, err := StashAndApply(r.Repo, "thread", tip); !errors.Is(err, ErrLocalCommits) {
		t.Fatal(err)
	}
	if list := r.Git("stash", "list"); list != "" {
		t.Fatal("a stash was created:", list)
	}
	if got, _ := os.ReadFile(filepath.Join(r.Dir, "f")); string(got) != "dirty\n" {
		t.Fatal("working tree changed")
	}
}

func TestWorktreeNextToCheckoutLeavesPrimaryUntouched(t *testing.T) {
	t.Parallel()
	r := base(t)
	tip := threadTip(t, r, "", map[string]string{"f": "thread\n"})
	r.Write("f", "dirty primary\n")
	statusBefore := r.Git("status", "--porcelain=v2", "--branch")
	res, err := Worktree(r.Repo, r.Dir, "fix-login", "yak/fix-login", tip)
	if err != nil {
		t.Fatal(err)
	}
	if res.Path != r.Dir+"-yak-fix-login" || res.Mode != "worktree" || res.Branch != "yak/fix-login" {
		t.Fatal(res)
	}
	if got, _ := os.ReadFile(filepath.Join(res.Path, "f")); string(got) != "thread\n" {
		t.Fatal(string(got))
	}
	if after := r.Git("status", "--porcelain=v2", "--branch"); after != statusBefore {
		t.Fatalf("primary changed:\n%s\n%s", statusBefore, after)
	}
}

func TestWorktreeUsesYakSuffixBranchWhenOnThreadBranch(t *testing.T) {
	t.Parallel()
	r := base(t)
	tip := threadTip(t, r, "", map[string]string{"f": "thread\n"})
	r.Git("switch", "-q", "-c", "yak/x")
	r.Write("f", "dirty\n")
	res, err := Worktree(r.Repo, r.Dir, "x", "yak/x", tip)
	if err != nil {
		t.Fatal(err)
	}
	if res.Branch != "yak/x-yak" {
		t.Fatal(res)
	}
	if b, _, _ := r.CurrentBranch(); b != "yak/x" {
		t.Fatal(b)
	}
	wt := testrepo.Repo{Repo: r.Repo}
	wt.Dir = res.Path
	if head, _ := wt.Head(); head != tip {
		t.Fatal(head)
	}
}

func TestNoForbiddenGitVerbsInSource(t *testing.T) {
	t.Parallel()
	src, err := os.ReadFile("apply.go")
	if err != nil {
		t.Fatal(err)
	}
	for _, bad := range []string{"merge", "rebase", "--force", "reset --hard", `"--hard"`, `"-f"`} {
		if strings.Contains(string(src), bad) {
			t.Errorf("apply.go mentions %q", bad)
		}
	}
}
