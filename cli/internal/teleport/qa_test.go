package teleport

import (
	"context"
	"errors"
	"strings"
	"testing"

	"github.com/geocodio/yak/cli/internal/config"
)

func TestDownWorktreeRoundTripPushesTheWorktreeBranchToTheThreadBranch(t *testing.T) {
	h := newHarness(t)
	h.setupDown(true)
	h.srv.Threads[42].PublicID = "k0000042"
	dirty(h)
	if _, err := h.down(DownOptions{Resolution: "worktree"}); err != nil {
		t.Fatal(err)
	}
	_, worktree := downFolders(h.cwd, "k0000042")
	if !strings.HasSuffix(worktree, "-yak-k0000042") {
		t.Fatalf("worktree folder %q is not named after the public id", worktree)
	}
	if branch, _, _ := (gitxRepo(worktree)).CurrentBranch(); branch != "yak/fix"+worktreeBranchSuffix {
		t.Fatalf("worktree is on %q", branch)
	}

	wt := gitxRepo(worktree)
	if _, err := wt.Run("-c", "user.name=t", "-c", "user.email=t@example.com", "commit", "--allow-empty", "-q", "-m", "from the worktree"); err != nil {
		t.Fatal(err)
	}
	want, _ := wt.Head()
	if _, err := Up(context.Background(), h.deps, UpInput{Cwd: worktree, SessionID: testSession}); err != nil {
		t.Fatalf("teleport up from the worktree: %v\n%s", err, h.out.String())
	}
	if got := h.originRef("refs/heads/yak/fix"); got != want {
		t.Fatalf("thread branch on origin is %s, want the worktree head %s", got, want)
	}
	if h.originRef("refs/heads/yak/fix-yak") != "" {
		t.Fatal("the worktree-only branch was pushed")
	}
}

func TestDownSaysPublicIDAndOmitsEmptyAccountName(t *testing.T) {
	h := newHarness(t)
	s := h.setupDown(true)
	h.srv.Threads[42].PublicID = "k0000042"
	s.manifest.Thread.ClaudeAccount.Name = ""
	h.rescript(s)
	if _, err := h.down(DownOptions{}); err != nil {
		t.Fatal(err)
	}
	out := h.out.String()
	if !strings.Contains(out, "Thread k0000042 is now on this computer.") || strings.Contains(out, "Thread 42") {
		t.Fatalf("%s", out)
	}
	if strings.Contains(out, "the  account") || strings.Contains(out, "not the") {
		t.Fatalf("empty account name printed:\n%s", out)
	}
	m, ok := config.ReadMarker(h.deps.ConfigDir, testSession)
	if !ok || m.ThreadPublicID != "k0000042" || m.ResumeEpoch == "" || m.ThreadRef() != "k0000042" {
		t.Fatalf("marker %+v", m)
	}
}

func TestUpOmitsAccountClauseWhenNoAccountIsAssigned(t *testing.T) {
	h := newHarness(t)
	h.local.Git("switch", "-q", "-c", "feat")
	h.writeTranscript(firstLine)
	h.srv.NoClaudeAccount = true
	if _, err := h.up(PrepareOptions{}); err != nil {
		t.Fatal(err)
	}
	out := h.out.String()
	if strings.Contains(out, "  ") && strings.Contains(out, "under the") || !strings.Contains(out, "Turns you run here use your local Claude login.") {
		t.Fatalf("%s", out)
	}
}

func TestDownDetachedHeadRefusalNamesTheRealReasonBeforeYakPreparesAnything(t *testing.T) {
	h := newHarness(t)
	h.setupDown(true)
	h.local.Git("checkout", "-q", "--detach")
	_, err := h.down(DownOptions{})
	var refusal *Refusal
	if !errors.As(err, &refusal) || !strings.Contains(refusal.Detail, "HEAD is detached") {
		t.Fatalf("got %v", err)
	}
	if got := h.requests("POST /teleports"); len(got) != 0 {
		t.Fatalf("Yak was asked to prepare: %+v", got)
	}
}
