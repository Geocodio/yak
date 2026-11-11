package clash

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/geocodio/yak/cli/internal/gitx/testrepo"
)

func setup(t *testing.T, ignoreCase bool) *testrepo.Repo {
	t.Helper()
	r := testrepo.New(t)
	r.Commit(map[string]string{"seed": "x"}, "seed")
	v := "false"
	if ignoreCase {
		v = "true"
	}
	r.Git("config", "core.ignorecase", v)
	return r
}

func check(t *testing.T, r *testrepo.Repo, entries []testrepo.Entry) Report {
	t.Helper()
	rep, err := Check(r.Repo, r.Tip(entries, ""))
	if err != nil {
		t.Fatal(err)
	}
	return rep
}

func only(t *testing.T, rep Report, kind string) Violation {
	t.Helper()
	if len(rep.Violations) != 1 || rep.Violations[0].Kind != kind {
		t.Fatalf("want one %s, got %+v", kind, rep.Violations)
	}
	return rep.Violations[0]
}

func TestCaseOnlyClashRefusedWhenIgnoreCase(t *testing.T) {
	t.Parallel()
	r := setup(t, true)
	v := only(t, check(t, r, []testrepo.Entry{{Path: "README.md"}, {Path: "readme.md"}}), "case_clash")
	if len(v.Paths) != 2 {
		t.Fatal(v)
	}
}

func TestCaseOnlyAllowedWhenCaseSensitive(t *testing.T) {
	t.Parallel()
	r := setup(t, false)
	if rep := check(t, r, []testrepo.Entry{{Path: "README.md"}, {Path: "readme.md"}}); !rep.Empty() {
		t.Fatal(rep)
	}
}

func TestDirectoryPrefixCaseClash(t *testing.T) {
	t.Parallel()
	r := setup(t, true)
	v := only(t, check(t, r, []testrepo.Entry{{Path: "Src/a.php"}, {Path: "src/b.php"}}), "case_clash")
	if v.Paths[0] != "Src" || v.Paths[1] != "src" {
		t.Fatal(v)
	}
}

func TestNFCvsNFDClash(t *testing.T) {
	t.Parallel()
	for _, ic := range []bool{false, true} {
		r := setup(t, ic)
		only(t, check(t, r, []testrepo.Entry{{Path: "café.txt"}, {Path: "café.txt"}}), "nfc_clash")
	}
}

func TestNFCCompositionPins(t *testing.T) {
	t.Parallel()
	if nfc("é") != "é" || nfc("å") != "å" {
		t.Fatal("composition table")
	}
	if nfc("plain") != "plain" || nfc("é") != "é" {
		t.Fatal("already composed")
	}
}

func TestNFDWithoutTwinIsInfoOnly(t *testing.T) {
	t.Parallel()
	r := setup(t, true)
	rep := check(t, r, []testrepo.Entry{{Path: "café.txt"}})
	if !rep.Empty() || len(rep.Info) != 1 {
		t.Fatalf("%+v", rep)
	}
}

func TestAbsoluteSymlinkRefused(t *testing.T) {
	t.Parallel()
	r := setup(t, false)
	only(t, check(t, r, []testrepo.Entry{{Path: "link", Mode: "120000", Content: "/etc/passwd"}}), "symlink_absolute")
}

func TestRelativeSymlinkInsideAllowed(t *testing.T) {
	t.Parallel()
	r := setup(t, false)
	rep := check(t, r, []testrepo.Entry{
		{Path: "a/real.txt", Content: "x"},
		{Path: "a/b/link", Mode: "120000", Content: "../real.txt"},
		{Path: "top", Mode: "120000", Content: "a/real.txt"},
	})
	if !rep.Empty() {
		t.Fatal(rep)
	}
}

func TestSymlinkEscapingRepoRefused(t *testing.T) {
	t.Parallel()
	r := setup(t, false)
	only(t, check(t, r, []testrepo.Entry{{Path: "x/link", Mode: "120000", Content: "../../x"}}), "symlink_escapes")
	only(t, check(t, r, []testrepo.Entry{{Path: "link", Mode: "120000", Content: "a/../../x"}}), "symlink_escapes")
}

func TestAllSymlinksRefusedWhenCoreSymlinksFalse(t *testing.T) {
	t.Parallel()
	r := setup(t, false)
	r.Git("config", "core.symlinks", "false")
	only(t, check(t, r, []testrepo.Entry{{Path: "link", Mode: "120000", Content: "target"}}), "symlink_unsupported")
}

func TestDotGitComponentRefused(t *testing.T) {
	t.Parallel()
	r := setup(t, false)
	for _, p := range []string{".git/config", "sub/.GIT/hooks/x", "a/.Git"} {
		only(t, check(t, r, []testrepo.Entry{{Path: p}}), "dot_git")
	}
	if rep := check(t, r, []testrepo.Entry{{Path: ".gitignore"}, {Path: "x/.github/ci.yml"}}); !rep.Empty() {
		t.Fatal(rep)
	}
}

func TestSubmoduleRefused(t *testing.T) {
	t.Parallel()
	r := setup(t, false)
	sha := r.Git("rev-parse", "HEAD")
	only(t, check(t, r, []testrepo.Entry{{Path: "vendor/lib", Mode: "160000", Content: sha}}), "submodule")
}

func TestCheckDoesNotModifyWorktree(t *testing.T) {
	t.Parallel()
	r := setup(t, true)
	r.Write("untracked.txt", "u")
	tip := r.Tip([]testrepo.Entry{{Path: "A"}, {Path: "a"}, {Path: "l", Mode: "120000", Content: "/x"}}, "")
	snap := func() (string, string, string) {
		idx, err := os.ReadFile(filepath.Join(r.Dir, ".git", "index"))
		if err != nil {
			t.Fatal(err)
		}
		return r.Git("status", "--porcelain=v2", "--branch"), string(idx), r.Git("stash", "list")
	}
	s1, i1, st1 := snap()
	if _, err := Check(r.Repo, tip); err != nil {
		t.Fatal(err)
	}
	s2, i2, st2 := snap()
	if s1 != s2 || i1 != i2 || st1 != st2 {
		t.Fatal("working tree, index or stash changed")
	}
	if _, err := os.Stat(filepath.Join(r.Dir, "A")); err == nil {
		t.Fatal("tip was checked out")
	}
}

func TestUnknownTipIsAnError(t *testing.T) {
	t.Parallel()
	r := setup(t, false)
	if _, err := Check(r.Repo, "deadbeefdeadbeefdeadbeefdeadbeefdeadbeef"); err == nil {
		t.Fatal("expected error")
	}
}

func TestSymlinkEscapeThroughTreeSymlinkRefused(t *testing.T) {
	t.Parallel()
	r := setup(t, false)
	v := only(t, check(t, r, []testrepo.Entry{
		{Path: "d/up", Mode: "120000", Content: ".."},
		{Path: "d/out", Mode: "120000", Content: "up/.."},
	}), "symlink_escapes")
	if v.Paths[0] != "d/out" {
		t.Fatalf("%+v", v)
	}
}

func TestSymlinkLoopRefused(t *testing.T) {
	t.Parallel()
	r := setup(t, false)
	rep := check(t, r, []testrepo.Entry{
		{Path: "a", Mode: "120000", Content: "b"},
		{Path: "b", Mode: "120000", Content: "a"},
	})
	if len(rep.Violations) != 2 || rep.Violations[0].Kind != "symlink_escapes" {
		t.Fatalf("%+v", rep.Violations)
	}
}

func TestSymlinkChainStayingInsideAllowed(t *testing.T) {
	t.Parallel()
	r := setup(t, false)
	rep := check(t, r, []testrepo.Entry{
		{Path: "d/file", Content: "x"},
		{Path: "d/up", Mode: "120000", Content: ".."},
		{Path: "d/ok", Mode: "120000", Content: "up/d/file"},
	})
	if !rep.Empty() {
		t.Fatal(rep)
	}
}
