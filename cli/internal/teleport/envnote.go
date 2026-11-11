package teleport

import (
	"bufio"
	"encoding/json"
	"errors"
	"io"
	"os"
	"path"
	"path/filepath"
	"strings"

	"github.com/geocodio/yak/cli/internal/gitx"
)

// CheckoutMapping ties a path inside the sandbox to the matching folder on this computer.
type CheckoutMapping struct {
	SandboxPath string
	LocalPath   string
}

// RefreshRule suggests Command when a changed file matches Glob.
type RefreshRule struct {
	Glob    string
	Command string
}

// NoteInput is everything the local environment note is built from.
type NoteInput struct {
	SandboxOS, SandboxArch string
	LocalOS, LocalArch     string // Go values: darwin, linux, arm64, amd64
	Primary                CheckoutMapping
	Extras                 []CheckoutMapping // /extras/<repo> mapped to the cloned folder
	Refresh                []RefreshRule
	// FromSHA and ToSHA bound the diff used to match refresh rules; an empty FromSHA skips them.
	FromSHA, ToSHA string
	// TouchedPaths come from TouchedPaths on the transcript.
	TouchedPaths []string
	// GitEnv entries (KEY=VALUE) are added to every git call; tests use it to isolate git.
	GitEnv []string
}

func osName(goos string) string {
	switch goos {
	case "darwin":
		return "macOS"
	case "linux":
		return "Linux"
	case "windows":
		return "Windows"
	}
	return goos
}

func archName(goarch string) string {
	if goarch == "amd64" {
		return "x86_64"
	}
	return goarch
}

// BuildLocalNote returns the text the SessionStart hook prints after a teleport down. Sections with nothing
// to say are left out.
func BuildLocalNote(in NoteInput) string {
	var b strings.Builder
	b.WriteString("[Yak] This session moved from Yak to this computer.\n")

	if in.SandboxOS != "" || in.LocalOS != "" {
		b.WriteString("\nSystem. " + osName(in.SandboxOS) + " " + archName(in.SandboxArch) + " -> " + osName(in.LocalOS) + " " + archName(in.LocalArch) + ".")
		if in.SandboxOS != in.LocalOS {
			b.WriteString(" Binaries built on " + osName(in.SandboxOS) + " will not run here.")
		}
		b.WriteString("\n")
	}

	if in.Primary.SandboxPath != "" && in.Primary.LocalPath != "" {
		b.WriteString("Paths. " + in.Primary.SandboxPath + " is now " + in.Primary.LocalPath + ". Treat " + in.Primary.SandboxPath + " in earlier messages as that path.\n")
		for _, extra := range in.Extras {
			b.WriteString("  " + extra.SandboxPath + " is now " + extra.LocalPath + ".\n")
		}
	}

	if lines := refreshLines(in); len(lines) > 0 {
		b.WriteString("Suggested commands. " + lines[0] + "\n")
		for _, line := range lines[1:] {
			b.WriteString("  " + line + "\n")
		}
	}

	if missing := missingFiles(in); len(missing) > 0 {
		b.WriteString("Missing here. These gitignored files were used in the thread and did not travel:\n")
		for _, m := range missing {
			b.WriteString("  " + m + "\n")
		}
		b.WriteString("  Ask the person before creating them. Do not guess their contents.\n")
	}
	return b.String()
}

func refreshLines(in NoteInput) []string {
	if in.FromSHA == "" || in.ToSHA == "" || len(in.Refresh) == 0 {
		return nil
	}
	out, err := gitx.Repo{Dir: in.Primary.LocalPath, Env: in.GitEnv}.RunBytes("diff", "--name-only", "-z", in.FromSHA, in.ToSHA)
	if err != nil {
		return nil
	}
	var changed []string
	for _, name := range strings.Split(string(out), "\x00") {
		if name != "" {
			changed = append(changed, name)
		}
	}
	var lines []string
	seen := map[string]bool{}
	for _, rule := range in.Refresh {
		for _, name := range changed {
			if globMatches(rule.Glob, name) && !seen[rule.Command] {
				seen[rule.Command] = true
				lines = append(lines, name+" changed on Yak: "+rule.Command)
				break
			}
		}
	}
	return lines
}

// globMatches matches the whole path, or the file name when the glob has no slash.
func globMatches(glob, name string) bool {
	if ok, _ := path.Match(glob, name); ok {
		return true
	}
	if !strings.Contains(glob, "/") {
		ok, _ := path.Match(glob, path.Base(name))
		return ok
	}
	return false
}

// missingFiles lists touched paths that git ignores and that do not exist on this computer.
func missingFiles(in NoteInput) []string {
	type candidate struct {
		root       int
		rel, local string
	}
	roots := append([]CheckoutMapping{in.Primary}, in.Extras...)
	var candidates []candidate
	byRoot := map[int][]string{}
	seen := map[string]bool{}
	for _, p := range in.TouchedPaths {
		for i, root := range roots {
			rel, ok := relativeTo(root, p, i == 0)
			if !ok {
				continue
			}
			local := filepath.Join(root.LocalPath, filepath.FromSlash(rel))
			if _, err := os.Lstat(local); errors.Is(err, os.ErrNotExist) && !seen[local] {
				seen[local] = true
				candidates = append(candidates, candidate{i, rel, local})
				byRoot[i] = append(byRoot[i], rel)
			}
			break
		}
	}
	ignored := map[string]bool{}
	for i, rels := range byRoot {
		repo := gitx.Repo{Dir: roots[i].LocalPath, Env: in.GitEnv}
		for start := 0; start < len(rels); start += 200 {
			end := min(start+200, len(rels))
			// check-ignore exits 1 when nothing matches; stdout is what counts.
			out, _ := repo.RunInput([]byte(strings.Join(rels[start:end], "\x00")+"\x00"), "check-ignore", "--stdin", "-z")
			for _, name := range strings.Split(string(out), "\x00") {
				if name != "" {
					ignored[filepath.Join(roots[i].LocalPath, filepath.FromSlash(name))] = true
				}
			}
		}
	}
	var missing []string
	for _, c := range candidates {
		switch {
		case !ignored[c.local]:
		case c.root == 0:
			missing = append(missing, c.rel)
		default:
			missing = append(missing, c.local)
		}
	}
	return missing
}

// relativeTo maps a transcript path into root and returns the cleaned repo-relative path. Relative
// transcript paths belong to the primary checkout. Anything that climbs out of the root is refused.
func relativeTo(root CheckoutMapping, p string, isPrimary bool) (string, bool) {
	var rest string
	switch {
	case root.SandboxPath != "" && strings.HasPrefix(p, root.SandboxPath+"/"):
		rest = strings.TrimPrefix(p, root.SandboxPath+"/")
	case isPrimary && !strings.HasPrefix(p, "/") && p != "":
		rest = p
	default:
		return "", false
	}
	rel := path.Clean(rest)
	if rel == "." || rel == ".." || strings.HasPrefix(rel, "../") || strings.HasPrefix(rel, "/") {
		return "", false
	}
	return rel, true
}

var pathTools = map[string]bool{"Read": true, "Write": true, "Edit": true, "Glob": true, "Grep": true}

// TouchedPaths returns, in order and without repeats, the file paths the transcript's Read, Write, Edit, Glob
// and Grep calls named. It streams the file and skips lines that are not valid JSON.
func TouchedPaths(transcriptPath string) ([]string, error) {
	f, err := os.Open(transcriptPath)
	if err != nil {
		return nil, err
	}
	defer f.Close()
	reader := bufio.NewReader(f)
	var out []string
	seen := map[string]bool{}
	add := func(p string) {
		if p != "" && !seen[p] {
			seen[p] = true
			out = append(out, p)
		}
	}
	for {
		line, err := reader.ReadBytes('\n')
		if len(line) > 0 {
			var row struct {
				Message struct {
					Content json.RawMessage `json:"content"`
				} `json:"message"`
			}
			var blocks []struct {
				Type  string `json:"type"`
				Name  string `json:"name"`
				Input struct {
					FilePath string `json:"file_path"`
					Path     string `json:"path"`
				} `json:"input"`
			}
			if json.Unmarshal(line, &row) == nil && json.Unmarshal(row.Message.Content, &blocks) == nil {
				for _, block := range blocks {
					if block.Type == "tool_use" && pathTools[block.Name] {
						add(block.Input.FilePath)
						add(block.Input.Path)
					}
				}
			}
		}
		if errors.Is(err, io.EOF) {
			return out, nil
		}
		if err != nil {
			return out, err
		}
	}
}
