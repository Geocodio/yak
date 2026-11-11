// Package teleport holds the flows that move a thread between this machine and Yak.
package teleport

import (
	"fmt"
	"sort"
	"strings"

	"github.com/geocodio/yak/cli/internal/detect"
	"github.com/geocodio/yak/cli/internal/gitx"
	"github.com/geocodio/yak/cli/internal/secrets"
	"github.com/geocodio/yak/cli/internal/ui"
)

// DefaultWIPMessage is the commit message for the WIP commit unless the person edits it.
const DefaultWIPMessage = "WIP: teleport to Yak"

// PrepareOptions steers PrepareUp. Without Interactive the prompter is never used: tracked changes are committed
// only with CommitTracked, only AddPaths of the untracked files are added, and secrets only with IncludeSecrets.
type PrepareOptions struct {
	Interactive    bool
	CommitTracked  bool
	AddPaths       []string
	CommitMessage  string
	IncludeSecrets bool
}

// Prepared is the checkout after PrepareUp. LeftUntracked lists untracked files that stay local.
type Prepared struct {
	Branch          string
	Head            string
	OnDefaultBranch bool
	WIPCommit       string
	// Detached means the WIP commit exists only as an object, not on any branch. WIPCommit and Head name it.
	Detached        bool
	PickedUntracked []string
	LeftUntracked   []string
}

// Refusal is a reason the teleport stops before anything is sent. Nothing changed locally unless the kind says
// otherwise. Kinds: operation_in_progress, detached_head, submodule_dirty, no_commits, tracked_changes,
// secret_excluded, unknown_path.
type Refusal struct {
	Kind   string
	Detail string
	Paths  []string
}

func (r *Refusal) Error() string {
	if len(r.Paths) == 0 {
		return r.Detail
	}
	return r.Detail + ": " + strings.Join(r.Paths, ", ")
}

// PrepareUp makes the checkout ready for teleport-up: refuses unsafe states, then commits the person's chosen
// changes as one WIP commit so the tracked tree is clean.
func PrepareUp(p ui.Prompter, repo gitx.Repo, opts PrepareOptions) (Prepared, error) {
	st, err := detect.Read(repo)
	if err != nil {
		return Prepared{}, err
	}
	if st.Operation != "" {
		return Prepared{}, &Refusal{Kind: "operation_in_progress", Detail: "a " + st.Operation + " is in progress, finish or abort it first"}
	}
	if st.Detached {
		return Prepared{}, &Refusal{Kind: "detached_head", Detail: "HEAD is detached, switch to a branch first"}
	}
	if st.Head == "" {
		return Prepared{}, &Refusal{Kind: "no_commits", Detail: "this branch has no commits yet"}
	}
	var dirtySubmodules []string
	for _, c := range st.Unstaged {
		if c.Kind == "submodule_dirty" {
			dirtySubmodules = append(dirtySubmodules, c.Path)
		}
	}
	if len(dirtySubmodules) > 0 {
		return Prepared{}, &Refusal{Kind: "submodule_dirty", Detail: "a submodule has local changes", Paths: dirtySubmodules}
	}

	tracked := trackedPaths(st)
	if err := checkTrackedSecrets(p, opts, tracked); err != nil {
		return Prepared{}, err
	}

	untracked := make([]string, 0, len(st.Untracked))
	for _, c := range st.Untracked {
		untracked = append(untracked, c.Path)
	}
	picked, err := pickUntracked(p, opts, untracked)
	if err != nil {
		return Prepared{}, err
	}

	if len(tracked) > 0 && !opts.Interactive && !opts.CommitTracked {
		return Prepared{}, &Refusal{Kind: "tracked_changes", Detail: "uncommitted changes to tracked files, commit them or pass --wip", Paths: tracked}
	}
	if len(tracked) > 0 && opts.Interactive {
		ok, err := p.Confirm(fmt.Sprintf("Commit %d changed tracked file(s) as a WIP commit so they travel with the thread?", len(tracked)), true)
		if err != nil {
			return Prepared{}, err
		}
		if !ok {
			return Prepared{}, &Refusal{Kind: "tracked_changes", Detail: "uncommitted changes to tracked files must be committed to teleport", Paths: tracked}
		}
	}

	prepared := Prepared{PickedUntracked: picked}
	if len(tracked) > 0 || len(picked) > 0 {
		message := opts.CommitMessage
		if message == "" {
			message = DefaultWIPMessage
			if opts.Interactive {
				if message, err = p.Edit("Commit message", DefaultWIPMessage); err != nil {
					return Prepared{}, err
				}
				if strings.TrimSpace(message) == "" {
					message = DefaultWIPMessage
				}
			}
		}
		paths := append(append([]string{}, tracked...), picked...)
		if onDefaultBranch(repo, st.Branch) {
			prepared.Detached = true
			prepared.WIPCommit, err = commitDetached(repo, paths, message)
		} else {
			prepared.WIPCommit, err = commit(repo, paths, message)
		}
		if err != nil {
			if prepared.Detached {
				discardDetached(repo)
			}
			return Prepared{}, err
		}
	}

	after, err := detect.Read(repo)
	if err != nil {
		return Prepared{}, err
	}
	if left := trackedPaths(after); len(left) > 0 && !prepared.Detached {
		return Prepared{}, &Refusal{Kind: "tracked_changes", Detail: "tracked files are still modified", Paths: left}
	}
	pickedSet := map[string]bool{}
	for _, path := range picked {
		pickedSet[path] = true
	}
	for _, c := range after.Untracked {
		if !pickedSet[c.Path] {
			prepared.LeftUntracked = append(prepared.LeftUntracked, c.Path)
		}
	}
	prepared.Branch, prepared.Head = after.Branch, after.Head
	if prepared.Detached {
		prepared.Head = prepared.WIPCommit
	}
	prepared.OnDefaultBranch = onDefaultBranch(repo, after.Branch)
	return prepared, nil
}

// trackedPaths lists every tracked path with staged or unstaged changes, sorted and unique.
func trackedPaths(st detect.Status) []string {
	seen := map[string]bool{}
	var paths []string
	for _, group := range [][]detect.Change{st.Staged, st.Unstaged} {
		for _, c := range group {
			if !seen[c.Path] {
				seen[c.Path] = true
				paths = append(paths, c.Path)
			}
		}
	}
	sort.Strings(paths)
	return paths
}

// checkTrackedSecrets asks about modified tracked files that look like secrets. The default answer is no, and a
// declined file stops the teleport because the tree would still be dirty.
func checkTrackedSecrets(p ui.Prompter, opts PrepareOptions, tracked []string) error {
	var declined []string
	for _, path := range tracked {
		if hit, _ := secrets.IsSecretFile(path); !hit || opts.IncludeSecrets {
			continue
		}
		include := false
		if opts.Interactive {
			var err error
			if include, err = p.Confirm(path+": this file looks like it holds secrets, include anyway?", false); err != nil {
				return err
			}
		}
		if !include {
			declined = append(declined, path)
		}
	}
	if len(declined) > 0 {
		return &Refusal{Kind: "secret_excluded", Detail: "modified files that look like secrets were left out, so the tree is still dirty", Paths: declined}
	}
	return nil
}

// pickUntracked returns the untracked files that go into the WIP commit. Secret-looking files are never
// preselected.
func pickUntracked(p ui.Prompter, opts PrepareOptions, untracked []string) ([]string, error) {
	if !opts.Interactive {
		known := map[string]bool{}
		for _, path := range untracked {
			known[path] = true
		}
		var bad []string
		for _, path := range opts.AddPaths {
			if !known[path] {
				bad = append(bad, path)
			}
		}
		if len(bad) > 0 {
			return nil, &Refusal{Kind: "unknown_path", Detail: "not an untracked file", Paths: bad}
		}
		var secret []string
		for _, path := range opts.AddPaths {
			if hit, _ := secrets.IsSecretFile(path); hit && !opts.IncludeSecrets {
				secret = append(secret, path)
			}
		}
		if len(secret) > 0 {
			return nil, &Refusal{Kind: "secret_excluded", Detail: "files that look like secrets need --include-secrets", Paths: secret}
		}
		return append([]string(nil), opts.AddPaths...), nil
	}
	if len(untracked) == 0 {
		return nil, nil
	}
	items := make([]ui.PickItem, len(untracked))
	for i, path := range untracked {
		items[i] = ui.PickItem{Label: path, Selected: true}
		if hit, _ := secrets.IsSecretFile(path); hit {
			items[i].Selected, items[i].Hint = false, "looks like a secret, not selected"
		}
	}
	idx, err := p.Pick("Untracked files to include in the WIP commit", items)
	if err != nil {
		return nil, err
	}
	picked := make([]string, 0, len(idx))
	for _, i := range idx {
		picked = append(picked, untracked[i])
	}
	return picked, nil
}

// commit stages exactly the given paths and commits the index. Hooks are skipped: a WIP snapshot must not be
// blocked by the person's own commit hooks.
func commit(repo gitx.Repo, paths []string, message string) (string, error) {
	if len(paths) > 0 {
		if _, err := repo.Run(append([]string{"add", "-A", "--"}, paths...)...); err != nil {
			return "", err
		}
	}
	if _, err := repo.Run("commit", "--no-verify", "-q", "-m", message); err != nil {
		return "", err
	}
	return repo.Head()
}

// commitDetached snapshots the given paths as a commit on top of HEAD without moving HEAD or any ref. The paths
// stay staged until the caller switches to a branch at the commit or calls discardDetached.
func commitDetached(repo gitx.Repo, paths []string, message string) (string, error) {
	if len(paths) > 0 {
		if _, err := repo.Run(append([]string{"add", "-A", "--"}, paths...)...); err != nil {
			return "", err
		}
	}
	tree, err := repo.Run("write-tree")
	if err != nil {
		return "", err
	}
	return repo.Run("commit-tree", strings.TrimSpace(tree), "-p", "HEAD", "-m", message)
}

// discardDetached puts the index back to HEAD and leaves the working tree as it is.
func discardDetached(repo gitx.Repo) {
	_, _ = repo.Run("reset", "-q")
}

func onDefaultBranch(repo gitx.Repo, branch string) bool {
	remotes, err := repo.Run("remote")
	if err != nil {
		return false
	}
	for _, remote := range strings.Fields(remotes) {
		if def, err := repo.DefaultBranch(remote); err == nil {
			return def == branch
		}
	}
	return false
}

// SwitchToNewBranch creates the branch at the given commit and switches to it. It refuses when the branch
// already exists.
func SwitchToNewBranch(repo gitx.Repo, branch, atSHA string) error {
	if _, err := repo.Run("rev-parse", "--verify", "-q", "refs/heads/"+branch); err == nil {
		return fmt.Errorf("branch %s already exists", branch)
	}
	_, err := repo.Run("switch", "-c", branch, atSHA)
	return err
}
