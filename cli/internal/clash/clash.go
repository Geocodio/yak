// Package clash decides, from the new tip's tree alone, whether it can be checked out safely on this machine.
package clash

import (
	"fmt"
	"path"
	"sort"
	"strings"

	"github.com/geocodio/yak/cli/internal/gitx"
)

// Violation is one reason to refuse. Kinds: case_clash, nfc_clash, symlink_absolute, symlink_escapes,
// symlink_unsupported, dot_git, submodule.
type Violation struct {
	Kind   string
	Paths  []string
	Detail string
}

// Report lists the refusals and the information-only findings.
type Report struct {
	Violations []Violation
	Info       []string
}

// Empty reports whether nothing blocks the checkout.
func (r Report) Empty() bool { return len(r.Violations) == 0 }

type entry struct{ mode, sha, path string }

func isTrue(v string, ok bool) bool {
	switch strings.ToLower(v) {
	case "true", "yes", "on", "1":
		return ok
	}
	return false
}

// Check reads the tree of tip with one ls-tree call and never touches the working tree, the index or HEAD.
func Check(repo gitx.Repo, tip string) (Report, error) {
	out, err := repo.RunBytes("ls-tree", "-r", "-z", "--full-tree", tip)
	if err != nil {
		return Report{}, err
	}
	ignoreCase := isTrue(repo.Config("core.ignorecase"))
	symlinksWork := true
	if v, ok := repo.Config("core.symlinks"); ok && !isTrue(v, ok) {
		symlinksWork = false
	}

	var entries []entry
	for _, rec := range strings.Split(string(out), "\x00") {
		if rec == "" {
			continue
		}
		meta, p, ok := strings.Cut(rec, "\t")
		f := strings.Fields(meta)
		if !ok || len(f) != 3 {
			return Report{}, fmt.Errorf("unparseable ls-tree record %q", rec)
		}
		entries = append(entries, entry{mode: f[0], sha: f[2], path: p})
	}

	links := map[string]string{}
	if symlinksWork {
		for _, e := range entries {
			if e.mode != "120000" {
				continue
			}
			blob, err := repo.RunBytes("cat-file", "blob", e.sha)
			if err != nil {
				return Report{}, err
			}
			links[e.path] = strings.ReplaceAll(string(blob), "\\", "/")
		}
	}

	var rep Report
	// Every path and every directory prefix, grouped by comparison key.
	prefixes := map[string]bool{}
	for _, e := range entries {
		parts := strings.Split(e.path, "/")
		for i := range parts {
			prefixes[strings.Join(parts[:i+1], "/")] = true
		}
	}
	groups := map[string][]string{}
	for p := range prefixes {
		k := foldKey(p, ignoreCase)
		groups[k] = append(groups[k], p)
	}
	keys := make([]string, 0, len(groups))
	for k := range groups {
		keys = append(keys, k)
	}
	sort.Strings(keys)
	flagged := map[string]bool{}
	for _, k := range keys {
		g := groups[k]
		sort.Strings(g)
		parent := path.Dir(k)
		if parent != "." && flagged[parent] {
			continue
		}
		if len(g) > 1 {
			flagged[k] = true
			kind, detail := "case_clash", "names differ only in letter case"
			if nfc(g[0]) == nfc(g[1]) {
				kind, detail = "nfc_clash", "names differ only in Unicode normalization"
			}
			rep.Violations = append(rep.Violations, Violation{Kind: kind, Paths: g, Detail: detail})
			continue
		}
		if last := path.Base(g[0]); nfc(last) != last {
			rep.Info = append(rep.Info, g[0]+": name is decomposed (NFD) and has no composed twin")
		}
	}

	for _, e := range entries {
		switch e.mode {
		case "160000":
			rep.Violations = append(rep.Violations, Violation{Kind: "submodule", Paths: []string{e.path}, Detail: "submodules are not supported"})
		case "120000":
			if v := checkSymlink(links, e, symlinksWork); v != nil {
				rep.Violations = append(rep.Violations, *v)
			}
		}
		for _, part := range strings.Split(e.path, "/") {
			if foldKey(part, true) == ".git" {
				rep.Violations = append(rep.Violations, Violation{Kind: "dot_git", Paths: []string{e.path}, Detail: "a path component named .git"})
				break
			}
		}
	}
	return rep, nil
}

// maxSymlinkDepth bounds how many tree symlinks one path may expand through, which also ends loops.
const maxSymlinkDepth = 40

func checkSymlink(links map[string]string, e entry, symlinksWork bool) *Violation {
	if !symlinksWork {
		return &Violation{Kind: "symlink_unsupported", Paths: []string{e.path}, Detail: "this checkout cannot create symlinks (core.symlinks is false)"}
	}
	target := links[e.path]
	if strings.HasPrefix(target, "/") || strings.HasPrefix(target, "\\") || (len(target) > 1 && target[1] == ':') {
		return &Violation{Kind: "symlink_absolute", Paths: []string{e.path}, Detail: "points to the absolute path " + target}
	}
	if !resolvesInTree(path.Dir(e.path), target, links) {
		return &Violation{Kind: "symlink_escapes", Paths: []string{e.path}, Detail: "points outside the repository: " + target}
	}
	return nil
}

// resolvesInTree follows target from dir component by component, expanding every prefix that is itself a
// symlink in the tree, and reports whether the result stays inside the repository.
func resolvesInTree(dir, target string, links map[string]string) bool {
	var stack []string
	if dir != "." {
		stack = strings.Split(dir, "/")
	}
	queue := strings.Split(target, "/")
	for expansions := 0; len(queue) > 0; {
		part := queue[0]
		queue = queue[1:]
		switch part {
		case "", ".":
			continue
		case "..":
			if len(stack) == 0 {
				return false
			}
			stack = stack[:len(stack)-1]
			continue
		}
		stack = append(stack, part)
		next, isLink := links[strings.Join(stack, "/")]
		if !isLink {
			continue
		}
		if expansions++; expansions > maxSymlinkDepth || strings.HasPrefix(next, "/") {
			return false
		}
		stack = stack[:len(stack)-1]
		queue = append(strings.Split(next, "/"), queue...)
	}
	return true
}
