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
	editAnswer    string
	pickIndexes   func(items []ui.PickItem) []int
	confirms      []string
	pickItems     []ui.PickItem
	edits         int
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
	f.pickItems = items
	if f.pickIndexes != nil {
		return f.pickIndexes(items), nil
	}
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
	if f.editAnswer != "" {
		return f.editAnswer, nil
	}
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
	if _, err := PrepareUp(&fakePrompter{editAnswer: "my wip"}, r.Repo, interactive()); err != nil {
		t.Fatal(err)
	}
	if r.Git("log", "-1", "--format=%s") != "my wip" {
		t.Fatal(r.Git("log", "-1", "--format=%s"))
	}
	r.Write("a.txt", "again\n")
	opts := interactive()
	opts.CommitMessage = "from flag"
	f := &fakePrompter{}
	if _, err := PrepareUp(f, r.Repo, opts); err != nil || f.edits != 0 {
		t.Fatal(err, f.edits)
	}
	if r.Git("log", "-1", "--format=%s") != "from flag" {
		t.Fatal("flag message")
	}
}

func TestUntrackedSecretsNeverPreselected(t *testing.T) {
	t.Parallel()
	r := repoWith(t)
	r.Write(".env.local", "x")
	r.Write("deploy/staging.pem", "x")
	r.Write("CredentialsPolicy.php", "x")
	r.Write("notes.txt", "x")
	f := &fakePrompter{}
	if _, err := PrepareUp(f, r.Repo, interactive()); err != nil {
		t.Fatal(err)
	}
	selected := map[string]bool{}
	for _, it := range f.pickItems {
		selected[it.Label] = it.Selected
		if !it.Selected && it.Hint != "looks like a secret, not selected" {
			t.Fatalf("hint for %s: %q", it.Label, it.Hint)
		}
	}
	want := map[string]bool{".env.local": false, "deploy/staging.pem": false, "CredentialsPolicy.php": true, "notes.txt": true}
	for k, v := range want {
		if got, ok := selected[k]; !ok || got != v {
			t.Fatalf("%s: selected=%v present=%v", k, got, ok)
		}
	}
}

func TestPickedUntrackedAreCommitted(t *testing.T) {
	t.Parallel()
	r := repoWith(t)
	r.Write("new.txt", "n")
	r.Write("skip.txt", "s")
	f := &fakePrompter{pickIndexes: func(items []ui.PickItem) []int { return []int{0} }}
	got, err := PrepareUp(f, r.Repo, interactive())
	if err != nil {
		t.Fatal(err)
	}
	if len(got.PickedUntracked) != 1 || got.PickedUntracked[0] != "new.txt" || got.WIPCommit == "" {
		t.Fatalf("%+v", got)
	}
	if r.Git("ls-files", "new.txt") == "" || r.Git("ls-files", "skip.txt") != "" {
		t.Fatal("wrong files committed")
	}
	if len(got.LeftUntracked) != 1 || got.LeftUntracked[0] != "skip.txt" {
		t.Fatalf("%+v", got.LeftUntracked)
	}
}

func TestUnpickedUntrackedStayLocalAndTeleportProceeds(t *testing.T) {
	t.Parallel()
	r := repoWith(t)
	r.Write("local-only.txt", "x")
	head, _ := r.Head()
	f := &fakePrompter{pickIndexes: func([]ui.PickItem) []int { return nil }}
	got, err := PrepareUp(f, r.Repo, interactive())
	if err != nil {
		t.Fatal(err)
	}
	if got.WIPCommit != "" || got.Head != head || len(got.LeftUntracked) != 1 {
		t.Fatalf("%+v", got)
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

func TestNonInteractiveRefusesDirtyWithoutFlags(t *testing.T) {
	t.Parallel()
	r := repoWith(t)
	r.Write("a.txt", "changed\n")
	r.Write("new.txt", "n")
	head, _ := r.Head()
	_, err := PrepareUp(ui.NewNonInteractive(), r.Repo, PrepareOptions{})
	var refusal *Refusal
	if !errors.As(err, &refusal) || refusal.Kind != "tracked_changes" || len(refusal.Paths) != 1 || refusal.Paths[0] != "a.txt" {
		t.Fatal(err)
	}
	if h, _ := r.Head(); h != head {
		t.Fatal("committed")
	}
	got, err := PrepareUp(ui.NewNonInteractive(), r.Repo, PrepareOptions{CommitTracked: true, AddPaths: []string{"new.txt"}})
	if err != nil || got.WIPCommit == "" || r.Git("ls-files", "new.txt") == "" {
		t.Fatal(got, err)
	}
}

func TestNonInteractiveSecretNeedsIncludeFlag(t *testing.T) {
	t.Parallel()
	r := repoWith(t)
	r.Write(".env", "SECRET=2\n")
	if _, err := PrepareUp(ui.NewNonInteractive(), r.Repo, PrepareOptions{CommitTracked: true}); err == nil {
		t.Fatal("secret must be refused")
	}
	if _, err := PrepareUp(ui.NewNonInteractive(), r.Repo, PrepareOptions{CommitTracked: true, IncludeSecrets: true}); err != nil {
		t.Fatal(err)
	}
}

func TestNonInteractiveUntrackedSecretAddPathNeedsIncludeFlag(t *testing.T) {
	t.Parallel()
	r := repoWith(t)
	r.Write(".env.local", "SECRET=2\n")
	_, err := PrepareUp(ui.NewNonInteractive(), r.Repo, PrepareOptions{AddPaths: []string{".env.local"}})
	var refusal *Refusal
	if !errors.As(err, &refusal) || refusal.Kind != "secret_excluded" || refusal.Paths[0] != ".env.local" {
		t.Fatal(err)
	}
	if _, err := PrepareUp(ui.NewNonInteractive(), r.Repo, PrepareOptions{AddPaths: []string{".env.local"}, IncludeSecrets: true}); err != nil {
		t.Fatal(err)
	}
}

func TestNonInteractiveUnknownAddPathRefuses(t *testing.T) {
	t.Parallel()
	r := repoWith(t)
	if _, err := PrepareUp(ui.NewNonInteractive(), r.Repo, PrepareOptions{AddPaths: []string{"nope.txt"}}); err == nil {
		t.Fatal("expected refusal")
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

func TestNonInteractiveDirtyTreeRefusalNamesWipFlag(t *testing.T) {
	t.Parallel()
	r := repoWith(t)
	r.Write("a.txt", "changed\n")
	_, err := PrepareUp(ui.NewNonInteractive(), r.Repo, PrepareOptions{})
	var refusal *Refusal
	if !errors.As(err, &refusal) || refusal.Kind != "tracked_changes" {
		t.Fatal(err)
	}
	if !strings.Contains(refusal.Detail, "--wip") || strings.Contains(refusal.Detail, "--commit") {
		t.Fatal(refusal.Detail)
	}
}
