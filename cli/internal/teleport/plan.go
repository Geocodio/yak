package teleport

import (
	"strconv"

	"github.com/geocodio/yak/cli/internal/detect"
	"github.com/geocodio/yak/cli/internal/gitx"
)

// upPlanEntry is one Yak repo from an added directory and what Yak will do with it.
type upPlanEntry struct{ Dir, Slug, Role, Reason string }

// classifyAddDir decides whether an added directory carries work Yak must take (changes) or is only reference
// material (context). It reads the checkout and never changes it.
func classifyAddDir(repo gitx.Repo) (role string, reason string, err error) {
	st, err := detect.Read(repo)
	if err != nil {
		return "", "", err
	}
	if st.Detached {
		return "changes", "detached HEAD", nil
	}
	if len(st.Staged)+len(st.Unstaged)+len(st.Untracked) > 0 {
		return "changes", "uncommitted changes", nil
	}
	if out, err := repo.Run("rev-list", "--count", "HEAD", "--not", "--remotes"); err == nil {
		if n, _ := strconv.Atoi(out); n > 0 {
			return "changes", "commits not on GitHub", nil
		}
	}
	remote, err := remoteFor(repo, "")
	if err != nil {
		return "", "", err
	}
	defaultBranch, err := repo.DefaultBranch(remote)
	if err != nil {
		return "", "", err
	}
	if st.Branch != defaultBranch {
		return "changes", "on branch " + st.Branch, nil
	}
	return "context", "nothing to carry", nil
}

// printUpPlan shows what Yak will do with each added directory before anything is asked or sent.
func printUpPlan(d Deps, entries []upPlanEntry) {
	if len(entries) == 0 {
		return
	}
	d.say("Added directories:")
	for _, e := range entries {
		d.say("  %s (%s): %s, %s", e.Dir, e.Slug, e.Role, e.Reason)
	}
}
