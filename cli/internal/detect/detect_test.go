package detect

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/geocodio/yak/cli/internal/gitx/testrepo"
)

func read(t *testing.T, r *testrepo.Repo) Status {
	t.Helper()
	s, err := Read(r.Repo)
	if err != nil {
		t.Fatal(err)
	}
	return s
}

func kinds(ds []Difference) map[string][]string {
	m := map[string][]string{}
	for _, d := range ds {
		m[d.Kind] = append(m[d.Kind], d.Path)
	}
	return m
}

func baselineOf(t *testing.T, r *testrepo.Repo) *Baseline {
	s := read(t, r)
	return &Baseline{Branch: s.Branch, Head: s.Head}
}

func TestCleanTreeNoDifferences(t *testing.T) {
	t.Parallel()
	r := testrepo.New(t)
	r.Commit(map[string]string{"a": "1"}, "a")
	s := read(t, r)
	if s.Branch != "main" || s.Detached || s.Head == "" {
		t.Fatalf("%+v", s)
	}
	if d := Differences(s, baselineOf(t, r)); len(d) != 0 {
		t.Fatal(d)
	}
	if d := Differences(s, nil); len(d) != 0 {
		t.Fatal("clean tree with no baseline", d)
	}
}

func TestIgnoredFilesNeverCount(t *testing.T) {
	t.Parallel()
	r := testrepo.New(t)
	r.Commit(map[string]string{".gitignore": "*.log\nbuild/\n"}, "ignore")
	r.Write("x.log", "x")
	r.Write("build/out.bin", "x")
	if d := Differences(read(t, r), nil); len(d) != 0 {
		t.Fatal(d)
	}
}

func TestUntrackedStagedUnstagedDeletedAndModeChange(t *testing.T) {
	t.Parallel()
	r := testrepo.New(t)
	r.Commit(map[string]string{"staged": "1", "unstaged": "1", "gone": "1", "exec": "1", "renamed": "same content here"}, "base")
	r.Write("staged", "2")
	r.Git("add", "staged")
	r.Write("unstaged", "2")
	if err := os.Remove(filepath.Join(r.Dir, "gone")); err != nil {
		t.Fatal(err)
	}
	if err := os.Chmod(filepath.Join(r.Dir, "exec"), 0o755); err != nil {
		t.Fatal(err)
	}
	r.Git("mv", "renamed", "renamed2")
	r.Write("dir/new.txt", "n")
	s := read(t, r)
	got := kinds(Differences(s, baselineOf(t, r)))
	want := map[string][]string{
		"staged":    {"renamed2", "staged"},
		"unstaged":  {"exec", "gone", "unstaged"},
		"untracked": {"dir/new.txt"},
	}
	for k, w := range want {
		if len(got[k]) != len(w) {
			t.Fatalf("%s: want %v got %v (all %v)", k, w, got[k], got)
		}
		for i := range w {
			if got[k][i] != w[i] {
				t.Fatalf("%s: want %v got %v", k, w, got[k])
			}
		}
	}
}

func TestMergeInProgressReported(t *testing.T) {
	t.Parallel()
	r := testrepo.New(t)
	r.Commit(map[string]string{"f": "base\n"}, "base")
	r.Git("switch", "-c", "other")
	r.Commit(map[string]string{"f": "other\n"}, "other")
	r.Git("switch", "main")
	r.Commit(map[string]string{"f": "main\n"}, "main")
	b := baselineOf(t, r)
	r.GitErr("merge", "other")
	s := read(t, r)
	if s.Operation != "merge" {
		t.Fatal(s.Operation)
	}
	if k := kinds(Differences(s, b)); len(k["operation_in_progress"]) == 0 {
		t.Fatal(k)
	}
}

func TestRebaseInProgressReported(t *testing.T) {
	t.Parallel()
	r := testrepo.New(t)
	r.Commit(map[string]string{"f": "base\n"}, "base")
	r.Git("switch", "-c", "other")
	r.Commit(map[string]string{"f": "other\n"}, "other")
	r.Git("switch", "main")
	r.Commit(map[string]string{"f": "main\n"}, "main")
	r.GitErr("rebase", "other")
	s := read(t, r)
	if s.Operation != "rebase" || !s.Detached {
		t.Fatalf("%+v", s)
	}
	if k := kinds(Differences(s, nil)); len(k["operation_in_progress"]) == 0 {
		t.Fatal(k)
	}
}

func TestDetachedHeadReported(t *testing.T) {
	t.Parallel()
	r := testrepo.New(t)
	r.Commit(map[string]string{"a": "1"}, "a")
	b := baselineOf(t, r)
	r.Git("switch", "--detach", "HEAD")
	s := read(t, r)
	if !s.Detached || s.Branch != "" {
		t.Fatalf("%+v", s)
	}
	if k := kinds(Differences(s, b)); len(k["detached_head"]) == 0 {
		t.Fatal(k)
	}
}

func TestOtherBranchReported(t *testing.T) {
	t.Parallel()
	r := testrepo.New(t)
	r.Commit(map[string]string{"a": "1"}, "a")
	b := baselineOf(t, r)
	r.Git("switch", "-c", "elsewhere")
	if k := kinds(Differences(read(t, r), b)); len(k["other_branch"]) == 0 {
		t.Fatal(k)
	}
}

func TestNewLocalCommitsReported(t *testing.T) {
	t.Parallel()
	r := testrepo.New(t)
	r.Commit(map[string]string{"a": "1"}, "a")
	b := baselineOf(t, r)
	r.Commit(map[string]string{"a": "2"}, "b")
	k := kinds(Differences(read(t, r), b))
	if len(k["new_commits"]) == 0 || len(k["not_descendant"]) != 0 {
		t.Fatal(k)
	}
}

func TestNotDescendantAfterResetReported(t *testing.T) {
	t.Parallel()
	r := testrepo.New(t)
	first := r.Commit(map[string]string{"a": "1"}, "a")
	r.Commit(map[string]string{"a": "2"}, "b")
	b := baselineOf(t, r)
	r.Git("reset", "-q", "--hard", first)
	k := kinds(Differences(read(t, r), b))
	if len(k["not_descendant"]) == 0 {
		t.Fatal(k)
	}
}

func TestNeverTeleportedRequiresClean(t *testing.T) {
	t.Parallel()
	r := testrepo.New(t)
	r.Commit(map[string]string{"a": "1"}, "a")
	r.Write("b", "x")
	k := kinds(Differences(read(t, r), nil))
	if len(k["untracked"]) != 1 {
		t.Fatal(k)
	}
}

func TestFilenamesWithSpacesAndNewlines(t *testing.T) {
	t.Parallel()
	r := testrepo.New(t)
	r.Commit(map[string]string{"a": "1"}, "a")
	r.Write("with space.txt", "x")
	r.Write("line\nbreak.txt", "x")
	r.Write("ünï/cødé.txt", "x")
	s := read(t, r)
	if len(s.Untracked) != 3 {
		t.Fatalf("%+v", s.Untracked)
	}
	seen := map[string]bool{}
	for _, c := range s.Untracked {
		seen[c.Path] = true
	}
	for _, p := range []string{"with space.txt", "line\nbreak.txt", "ünï/cødé.txt"} {
		if !seen[p] {
			t.Fatalf("missing %q in %v", p, seen)
		}
	}
}

func TestSubmoduleDirtyReported(t *testing.T) {
	t.Parallel()
	inner := testrepo.New(t)
	inner.Commit(map[string]string{"x": "1"}, "x")
	r := testrepo.New(t)
	r.Commit(map[string]string{"a": "1"}, "a")
	r.Git("-c", "protocol.file.allow=always", "submodule", "add", "-q", inner.Dir, "sub")
	r.Git("commit", "-q", "-m", "add sub")
	b := baselineOf(t, r)
	if d := Differences(read(t, r), b); len(d) != 0 {
		t.Fatal(d)
	}
	if err := os.WriteFile(filepath.Join(r.Dir, "sub", "x"), []byte("dirty"), 0o644); err != nil {
		t.Fatal(err)
	}
	k := kinds(Differences(read(t, r), b))
	if len(k["submodule_dirty"]) != 1 || k["submodule_dirty"][0] != "sub" {
		t.Fatal(k)
	}
}

func TestUnbornBranch(t *testing.T) {
	t.Parallel()
	r := testrepo.New(t)
	s := read(t, r)
	if s.Branch != "main" || s.Head != "" {
		t.Fatalf("%+v", s)
	}
}
