package teleport

import (
	"errors"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/geocodio/yak/cli/internal/gitx/testrepo"
	"github.com/geocodio/yak/cli/internal/ui"
)

// fakePrompter answers like a person pressing Enter unless told otherwise, and records what it was asked.
type fakePrompter struct {
	confirmAnswer map[string]bool // substring of the question -> answer
	confirms      []string
	picks, edits  int
}

func (f *fakePrompter) Confirm(q string, def bool) (bool, error) {
	f.confirms = append(f.confirms, q)
	for sub, ans := range f.confirmAnswer {
		if strings.Contains(q, sub) {
			return ans, nil
		}
	}
	return def, nil
}
func (f *fakePrompter) Choose(q string, o []ui.Option, def int) (int, error) { return def, nil }
func (f *fakePrompter) Pick(q string, items []ui.PickItem) ([]int, error) {
	f.picks++
	var idx []int
	for i, it := range items {
		if it.Selected {
			idx = append(idx, i)
		}
	}
	return idx, nil
}
func (f *fakePrompter) Edit(label, cur string) (string, error) {
	f.edits++
	return cur, nil
}

func repoWith(t *testing.T) *testrepo.Repo {
	r := testrepo.New(t)
	r.Commit(map[string]string{"a.txt": "a\n", ".env": "SECRET=1\n"}, "base")
	return r
}

func interactive() PrepareOptions { return PrepareOptions{Interactive: true} }

func TestCleanTreePassesThrough(t *testing.T) {
	t.Parallel()
	r := repoWith(t)
	head, _ := r.Head()
	got, err := PrepareUp(&fakePrompter{}, r.Repo, interactive())
	if err != nil {
		t.Fatal(err)
	}
	if got.Head != head || got.Branch != "main" || got.WIPCommit != "" || r.Git("rev-list", "--count", "HEAD") != "1" {
		t.Fatalf("%+v", got)
	}
}

func TestDirtyTrackedCommittedAsWIPMessage(t *testing.T) {
	t.Parallel()
	r := repoWith(t)
	r.Write("a.txt", "changed\n")
	got, err := PrepareUp(&fakePrompter{}, r.Repo, interactive())
	if err != nil {
		t.Fatal(err)
	}
	if got.WIPCommit == "" || r.Git("log", "-1", "--format=%s") != "WIP: teleport to Yak" {
		t.Fatalf("%+v", got)
	}
	if head, _ := r.Head(); head != got.Head || head != got.WIPCommit {
		t.Fatal("head must be the WIP commit")
	}
	if r.Git("status", "--porcelain") != "" {
		t.Fatal("tree must be clean")
	}
}

func TestCustomMessageUsed(t *testing.T) {
	t.Parallel()
	r := repoWith(t)
	r.Write("a.txt", "changed\n")
	opts := interactive()
	opts.CommitMessage = "from flag"
	if _, err := PrepareUp(&fakePrompter{}, r.Repo, opts); err != nil {
		t.Fatal(err)
	}
	if r.Git("log", "-1", "--format=%s") != "from flag" {
		t.Fatal("flag message")
	}
}

func TestUntrackedSecretsStayLocalUnlessIncluded(t *testing.T) {
	t.Parallel()
	r := repoWith(t)
	r.Write(".env.local", "x")
	r.Write("deploy/staging.pem", "x")
	r.Write("CredentialsPolicy.php", "x")
	r.Write("notes.txt", "x")
	got, err := PrepareUp(&fakePrompter{}, r.Repo, interactive())
	if err != nil {
		t.Fatal(err)
	}
	if strings.Join(got.LeftUntracked, ",") != ".env.local,deploy/staging.pem" {
		t.Fatalf("left %v", got.LeftUntracked)
	}
	if r.Git("ls-files", "CredentialsPolicy.php", "notes.txt") == "" {
		t.Fatal("non-secret files must be committed")
	}
}

func TestEveryChangeIsCommittedWithoutAsking(t *testing.T) {
	t.Parallel()
	r := repoWith(t)
	r.Write("a.txt", "changed\n")
	r.Write("new.txt", "n")
	r.Write("dir/other.txt", "o")
	f := &fakePrompter{}
	got, err := PrepareUp(f, r.Repo, interactive())
	if err != nil {
		t.Fatal(err)
	}
	if got.WIPCommit == "" || len(got.LeftUntracked) != 0 || r.Git("status", "--porcelain") != "" {
		t.Fatalf("%+v", got)
	}
	if len(f.confirms) != 0 || f.picks != 0 || f.edits != 0 {
		t.Fatalf("prompted: %v picks=%d edits=%d", f.confirms, f.picks, f.edits)
	}
}

func TestTrackedModifiedSecretDefaultsToExcluded(t *testing.T) {
	t.Parallel()
	r := repoWith(t)
	r.Write(".env", "SECRET=2\n")
	head, _ := r.Head()
	f := &fakePrompter{}
	_, err := PrepareUp(f, r.Repo, interactive())
	var refusal *Refusal
	if !errors.As(err, &refusal) || refusal.Kind != "secret_excluded" || refusal.Paths[0] != ".env" {
		t.Fatal(err)
	}
	if len(f.confirms) != 1 || !strings.Contains(f.confirms[0], "this file looks like it holds secrets, include anyway?") {
		t.Fatal(f.confirms)
	}
	if h, _ := r.Head(); h != head {
		t.Fatal("nothing may be committed")
	}
}

func TestTrackedModifiedSecretIncludedWhenConfirmed(t *testing.T) {
	t.Parallel()
	r := repoWith(t)
	r.Write(".env", "SECRET=2\n")
	f := &fakePrompter{confirmAnswer: map[string]bool{"include anyway": true}}
	got, err := PrepareUp(f, r.Repo, interactive())
	if err != nil || got.WIPCommit == "" {
		t.Fatal(got, err)
	}
}

func TestMergeInProgressRefusesWithoutChange(t *testing.T) {
	t.Parallel()
	r := testrepo.New(t)
	r.Commit(map[string]string{"f": "base\n"}, "base")
	r.Git("switch", "-q", "-c", "other")
	r.Commit(map[string]string{"f": "other\n"}, "other")
	r.Git("switch", "-q", "main")
	r.Commit(map[string]string{"f": "main\n"}, "main")
	r.GitErr("merge", "other")
	head, status := r.Git("rev-parse", "HEAD"), r.Git("status", "--porcelain=v2", "--branch")
	_, err := PrepareUp(&fakePrompter{}, r.Repo, interactive())
	var refusal *Refusal
	if !errors.As(err, &refusal) || refusal.Kind != "operation_in_progress" || !strings.Contains(err.Error(), "merge") {
		t.Fatal(err)
	}
	if r.Git("rev-parse", "HEAD") != head || r.Git("status", "--porcelain=v2", "--branch") != status {
		t.Fatal("repo changed")
	}
}

func TestDetachedHeadRefuses(t *testing.T) {
	t.Parallel()
	r := repoWith(t)
	r.Git("switch", "-q", "--detach", "HEAD")
	_, err := PrepareUp(&fakePrompter{}, r.Repo, interactive())
	var refusal *Refusal
	if !errors.As(err, &refusal) || refusal.Kind != "detached_head" {
		t.Fatal(err)
	}
}

func TestDirtySubmoduleRefuses(t *testing.T) {
	t.Parallel()
	inner := testrepo.New(t)
	inner.Commit(map[string]string{"x": "1"}, "x")
	r := repoWith(t)
	r.Git("-c", "protocol.file.allow=always", "submodule", "add", "-q", inner.Dir, "sub")
	r.Git("commit", "-q", "-m", "sub")
	if err := os.WriteFile(filepath.Join(r.Dir, "sub", "x"), []byte("dirty"), 0o644); err != nil {
		t.Fatal(err)
	}
	_, err := PrepareUp(&fakePrompter{}, r.Repo, interactive())
	var refusal *Refusal
	if !errors.As(err, &refusal) || refusal.Kind != "submodule_dirty" {
		t.Fatal(err)
	}
}

func TestNonInteractiveCommitsEveryChange(t *testing.T) {
	t.Parallel()
	r := repoWith(t)
	r.Write("a.txt", "changed\n")
	r.Write("new.txt", "n")
	got, err := PrepareUp(ui.NewNonInteractive(), r.Repo, PrepareOptions{})
	if err != nil || got.WIPCommit == "" || r.Git("status", "--porcelain") != "" {
		t.Fatal(got, err)
	}
}

func TestNonInteractiveSecretNeedsIncludeFlag(t *testing.T) {
	t.Parallel()
	r := repoWith(t)
	r.Write(".env", "SECRET=2\n")
	if _, err := PrepareUp(ui.NewNonInteractive(), r.Repo, PrepareOptions{}); err == nil {
		t.Fatal("secret must be refused")
	}
	if _, err := PrepareUp(ui.NewNonInteractive(), r.Repo, PrepareOptions{IncludeSecrets: true}); err != nil {
		t.Fatal(err)
	}
}

func TestOnDefaultBranch(t *testing.T) {
	t.Parallel()
	origin := testrepo.New(t)
	origin.Commit(map[string]string{"a": "1"}, "a")
	c := testrepo.Clone(t, origin.Dir)
	got, err := PrepareUp(&fakePrompter{}, c.Repo, interactive())
	if err != nil || !got.OnDefaultBranch {
		t.Fatal(got, err)
	}
	c.Git("switch", "-q", "-c", "feature")
	got, err = PrepareUp(&fakePrompter{}, c.Repo, interactive())
	if err != nil || got.OnDefaultBranch || got.Branch != "feature" {
		t.Fatal(got, err)
	}
}

func TestSwitchToNewBranchAtSHA(t *testing.T) {
	t.Parallel()
	r := repoWith(t)
	sha, _ := r.Head()
	if err := SwitchToNewBranch(r.Repo, "yak/fix", sha); err != nil {
		t.Fatal(err)
	}
	if b, _, _ := r.CurrentBranch(); b != "yak/fix" {
		t.Fatal(b)
	}
	if err := SwitchToNewBranch(r.Repo, "yak/fix", sha); err == nil {
		t.Fatal("existing branch must be refused")
	}
}
