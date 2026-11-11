package gitx_test

import (
	"testing"

	"github.com/geocodio/yak/cli/internal/gitx/testrepo"
)

func TestRunAndQueries(t *testing.T) {
	t.Parallel()
	r := testrepo.New(t)
	sha := r.Commit(map[string]string{"a.txt": "a"}, "first")
	if got, err := r.Head(); err != nil || got != sha {
		t.Fatal(got, err)
	}
	b, detached, err := r.CurrentBranch()
	if err != nil || detached || b != "main" {
		t.Fatal(b, detached, err)
	}
	top, err := r.Toplevel()
	if err != nil || top == "" {
		t.Fatal(top, err)
	}
	if _, err := r.Run("no-such-verb"); err == nil {
		t.Fatal("expected error")
	}
	if v, ok := r.Config("user.email"); !ok || v == "" {
		t.Fatal(v, ok)
	}
	if _, ok := r.Config("no.such.key"); ok {
		t.Fatal("missing key")
	}
	r.Git("switch", "--detach", "HEAD")
	if _, detached, _ := r.CurrentBranch(); !detached {
		t.Fatal("detached head")
	}
}

func TestIsAncestor(t *testing.T) {
	t.Parallel()
	r := testrepo.New(t)
	a := r.Commit(map[string]string{"a": "1"}, "a")
	b := r.Commit(map[string]string{"a": "2"}, "b")
	if ok, err := r.IsAncestor(a, b); err != nil || !ok {
		t.Fatal(ok, err)
	}
	if ok, err := r.IsAncestor(b, a); err != nil || ok {
		t.Fatal(ok, err)
	}
	if _, err := r.IsAncestor("deadbeef", b); err == nil {
		t.Fatal("unknown commit is an error")
	}
}

func TestRemotesAndDefaultBranch(t *testing.T) {
	t.Parallel()
	origin := testrepo.New(t)
	origin.Commit(map[string]string{"a": "1"}, "a")
	c := testrepo.Clone(t, origin.Dir)
	remotes, err := c.Remotes()
	if err != nil || len(remotes) != 1 || remotes[0] != origin.Dir {
		t.Fatal(remotes, err)
	}
	if b, err := c.DefaultBranch("origin"); err != nil || b != "main" {
		t.Fatal(b, err)
	}
	if _, err := c.DefaultBranch("nope"); err == nil {
		t.Fatal("unknown remote")
	}
}

func TestInProgressOperationDetectsMergeConflict(t *testing.T) {
	t.Parallel()
	r := testrepo.New(t)
	r.Commit(map[string]string{"f": "base\n"}, "base")
	r.Git("switch", "-c", "other")
	r.Commit(map[string]string{"f": "other\n"}, "other")
	r.Git("switch", "main")
	r.Commit(map[string]string{"f": "main\n"}, "main")
	if op, err := r.InProgressOperation(); err != nil || op != "" {
		t.Fatal(op, err)
	}
	if _, err := r.GitErr("merge", "other"); err == nil {
		t.Fatal("expected conflict")
	}
	if op, err := r.InProgressOperation(); err != nil || op != "merge" {
		t.Fatal(op, err)
	}
}

func TestInProgressOperationRebaseCherryPickRevertBisect(t *testing.T) {
	t.Parallel()
	for _, tc := range []struct {
		op    string
		start func(r *testrepo.Repo, other string)
	}{
		{"rebase", func(r *testrepo.Repo, other string) { r.GitErr("rebase", other) }},
		{"cherry-pick", func(r *testrepo.Repo, other string) { r.GitErr("cherry-pick", other) }},
		{"revert", func(r *testrepo.Repo, other string) { r.GitErr("revert", "--no-edit", other) }},
		{"bisect", func(r *testrepo.Repo, other string) { r.Git("bisect", "start") }},
	} {
		t.Run(tc.op, func(t *testing.T) {
			t.Parallel()
			r := testrepo.New(t)
			r.Commit(map[string]string{"f": "base\n"}, "base")
			r.Git("switch", "-c", "other")
			other := r.Commit(map[string]string{"f": "other\n"}, "other")
			r.Git("switch", "main")
			r.Commit(map[string]string{"f": "main\n"}, "main")
			tc.start(r, other)
			if op, err := r.InProgressOperation(); err != nil || op != tc.op {
				t.Fatalf("want %s got %q %v", tc.op, op, err)
			}
		})
	}
}
