// Package detect reads the state of a checkout with one git status call and compares it with the last teleport.
package detect

import (
	"fmt"
	"strings"

	"github.com/geocodio/yak/cli/internal/gitx"
)

// Change is one path that differs from HEAD. Kind is added, modified, deleted, renamed, copied, typechange,
// unmerged, submodule_dirty or untracked.
type Change struct{ Path, Kind string }

// Status is the checkout state. Head is empty on an unborn branch. Submodules with local changes appear in
// Unstaged with Kind submodule_dirty.
type Status struct {
	Branch    string
	Detached  bool
	Head      string
	Operation string
	Staged    []Change
	Unstaged  []Change
	Untracked []Change

	repo gitx.Repo
}

// Baseline is the branch and commit of the last teleport-up.
type Baseline struct{ Branch, Head string }

// Difference is one reason the checkout is not exactly where the last teleport left it.
// Kinds: other_branch, detached_head, new_commits, not_descendant, operation_in_progress, staged, unstaged,
// untracked, submodule_dirty.
type Difference struct{ Kind, Path, Detail string }

var kindByLetter = map[byte]string{
	'M': "modified", 'A': "added", 'D': "deleted", 'R': "renamed", 'C': "copied", 'T': "typechange", 'U': "unmerged",
}

// Read runs a single `git status --porcelain=v2 --branch -z --untracked-files=all`. Ignored files never appear.
func Read(repo gitx.Repo) (Status, error) {
	out, err := repo.RunBytes("status", "--porcelain=v2", "--branch", "-z", "--untracked-files=all")
	if err != nil {
		return Status{}, err
	}
	s := Status{repo: repo}
	records := strings.Split(string(out), "\x00")
	for i := 0; i < len(records); i++ {
		rec := records[i]
		if rec == "" {
			continue
		}
		switch {
		case strings.HasPrefix(rec, "# branch.oid "):
			if v := strings.TrimPrefix(rec, "# branch.oid "); v != "(initial)" {
				s.Head = v
			}
		case strings.HasPrefix(rec, "# branch.head "):
			if v := strings.TrimPrefix(rec, "# branch.head "); v == "(detached)" {
				s.Detached = true
			} else {
				s.Branch = v
			}
		case strings.HasPrefix(rec, "# "):
		case rec[0] == '?':
			s.Untracked = append(s.Untracked, Change{Path: rec[2:], Kind: "untracked"})
		case rec[0] == '1', rec[0] == '2', rec[0] == 'u':
			fields := 9 // "1 XY sub mH mI mW hH hI path"
			switch rec[0] {
			case '2':
				fields = 10
			case 'u':
				fields = 11
			}
			f := strings.SplitN(rec, " ", fields)
			if len(f) < fields {
				return Status{}, fmt.Errorf("unparseable status record %q", rec)
			}
			xy, path := f[1], f[fields-1]
			if rec[0] == '2' {
				i++ // the original path follows as its own record
			}
			if rec[0] == 'u' {
				s.Unstaged = append(s.Unstaged, Change{Path: path, Kind: "unmerged"})
				continue
			}
			if sub := f[2]; sub[0] == 'S' && (sub[2] != '.' || sub[3] != '.') {
				s.Unstaged = append(s.Unstaged, Change{Path: path, Kind: "submodule_dirty"})
				if xy[0] != '.' {
					s.Staged = append(s.Staged, Change{Path: path, Kind: kindByLetter[xy[0]]})
				}
				continue
			}
			if xy[0] != '.' {
				s.Staged = append(s.Staged, Change{Path: path, Kind: kindByLetter[xy[0]]})
			}
			if xy[1] != '.' {
				s.Unstaged = append(s.Unstaged, Change{Path: path, Kind: kindByLetter[xy[1]]})
			}
		}
	}
	if s.Operation, err = repo.InProgressOperation(); err != nil {
		return Status{}, err
	}
	return s, nil
}

// Differences lists everything that separates the checkout from the last teleport-up. A nil baseline means the
// thread was never teleported up, which only allows a clean tree.
func Differences(s Status, base *Baseline) []Difference {
	var ds []Difference
	if s.Operation != "" {
		ds = append(ds, Difference{Kind: "operation_in_progress", Detail: s.Operation + " in progress"})
	}
	if s.Detached {
		ds = append(ds, Difference{Kind: "detached_head", Detail: "HEAD is detached"})
	}
	if base != nil {
		if !s.Detached && s.Branch != base.Branch {
			ds = append(ds, Difference{Kind: "other_branch", Path: s.Branch, Detail: fmt.Sprintf("on %s, last teleport was from %s", s.Branch, base.Branch)})
		}
		if s.Head != base.Head {
			ok := false
			if s.Head != "" && base.Head != "" && s.repo.Dir != "" {
				ok, _ = s.repo.IsAncestor(base.Head, s.Head)
			}
			if ok {
				ds = append(ds, Difference{Kind: "new_commits", Detail: "commits made since the last teleport"})
			} else {
				ds = append(ds, Difference{Kind: "not_descendant", Detail: "HEAD is not a descendant of the last teleport commit"})
			}
		}
	}
	for _, c := range s.Staged {
		ds = append(ds, Difference{Kind: "staged", Path: c.Path, Detail: c.Kind})
	}
	for _, c := range s.Unstaged {
		if c.Kind == "submodule_dirty" {
			ds = append(ds, Difference{Kind: "submodule_dirty", Path: c.Path, Detail: "submodule has local changes"})
			continue
		}
		ds = append(ds, Difference{Kind: "unstaged", Path: c.Path, Detail: c.Kind})
	}
	for _, c := range s.Untracked {
		ds = append(ds, Difference{Kind: "untracked", Path: c.Path, Detail: c.Kind})
	}
	return ds
}
