package teleport

import (
	"testing"

	"github.com/geocodio/yak/cli/internal/gitx/testrepo"
)

// cleanClone is a checkout of a pushed main branch with nothing to carry.
func cleanClone(t *testing.T) (*testrepo.Repo, string) {
	t.Helper()
	isolateGit(t)
	bare := testrepo.Bare(t)
	seed := testrepo.Clone(t, bare)
	seed.Git("switch", "-q", "-c", "main")
	seed.Commit(map[string]string{"README.md": "hi\n"}, "initial")
	seed.Git("push", "-q", "-u", "origin", "main")
	return testrepo.Clone(t, bare), bare
}

func assertClassified(t *testing.T, repo *testrepo.Repo, wantRole, wantReason string) {
	t.Helper()
	role, reason, err := classifyAddDir(repo.Repo)
	if err != nil {
		t.Fatal(err)
	}
	if role != wantRole || reason != wantReason {
		t.Fatalf("got %q %q, want %q %q", role, reason, wantRole, wantReason)
	}
}

func TestClassifyCleanDefaultBranchIsContext(t *testing.T) {
	repo, _ := cleanClone(t)
	assertClassified(t, repo, "context", "nothing to carry")
}

func TestClassifyDirtyTreeIsChanges(t *testing.T) {
	repo, _ := cleanClone(t)
	repo.Write("README.md", "changed\n")
	assertClassified(t, repo, "changes", "uncommitted changes")
	repo.Git("checkout", "--", "README.md")
	repo.Write("new.txt", "x")
	assertClassified(t, repo, "changes", "uncommitted changes")
}

func TestClassifyUnpushedCommitIsChanges(t *testing.T) {
	repo, _ := cleanClone(t)
	repo.Commit(map[string]string{"a.txt": "a"}, "local work")
	assertClassified(t, repo, "changes", "commits not on GitHub")
}

func TestClassifyNonDefaultBranchIsChanges(t *testing.T) {
	repo, _ := cleanClone(t)
	repo.Git("switch", "-q", "-c", "feat")
	assertClassified(t, repo, "changes", "on branch feat")
}

func TestClassifyIgnoredFilesDoNotCount(t *testing.T) {
	repo, _ := cleanClone(t)
	repo.Commit(map[string]string{".gitignore": "*.log\n"}, "ignore logs")
	repo.Git("push", "-q", "origin", "main")
	repo.Write("debug.log", "noise")
	assertClassified(t, repo, "context", "nothing to carry")
}

func TestClassifyDetachedHeadIsChanges(t *testing.T) {
	repo, _ := cleanClone(t)
	repo.Git("checkout", "-q", "--detach")
	assertClassified(t, repo, "changes", "detached HEAD")
}
