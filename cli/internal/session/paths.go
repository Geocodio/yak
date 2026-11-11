package session

import (
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

// ClaudeConfigDir is $CLAUDE_CONFIG_DIR, else ~/.claude.
func ClaudeConfigDir(env func(string) string, home string) string {
	if d := env("CLAUDE_CONFIG_DIR"); d != "" {
		return d
	}
	return filepath.Join(home, ".claude")
}

// ProjectFolderName is the folder Claude Code keeps a working directory's sessions in.
func ProjectFolderName(cwd string) string {
	return strings.ReplaceAll(cwd, "/", "-")
}

// ValidSessionID reports whether id is a UUID.
func ValidSessionID(id string) bool {
	if len(id) != 36 {
		return false
	}
	for i, c := range id {
		switch {
		case i == 8 || i == 13 || i == 18 || i == 23:
			if c != '-' {
				return false
			}
		case !(c >= '0' && c <= '9' || c >= 'a' && c <= 'f' || c >= 'A' && c <= 'F'):
			return false
		}
	}
	return true
}

// FindSessionFile returns the path of <sessionID>.jsonl, preferring the folder for cwd and otherwise
// searching every project folder, since resume finds a session by id.
func FindSessionFile(configDir, cwd, sessionID string) (string, error) {
	if !ValidSessionID(sessionID) {
		return "", fmt.Errorf("%q is not a session id", sessionID)
	}
	preferred := filepath.Join(configDir, "projects", ProjectFolderName(cwd), sessionID+".jsonl")
	if info, err := os.Stat(preferred); err == nil && info.Mode().IsRegular() {
		return preferred, nil
	}
	matches, err := filepath.Glob(filepath.Join(configDir, "projects", "*", sessionID+".jsonl"))
	if err != nil {
		return "", err
	}
	for _, m := range matches {
		if info, err := os.Stat(m); err == nil && info.Mode().IsRegular() {
			return m, nil
		}
	}
	return "", errors.New("could not find the session file for " + sessionID)
}

// ValidateRelPath accepts only the shapes a session folder may contain:
// <sid>.jsonl, <sid>/subagents/<name>.jsonl|.meta.json, <sid>/tool-results/<path>, memory/<name>.
func ValidateRelPath(rel string) error {
	bad := fmt.Errorf("unsafe session path %q", rel)
	if rel == "" || strings.HasPrefix(rel, "/") || strings.ContainsAny(rel, "\\\x00") {
		return bad
	}
	parts := strings.Split(rel, "/")
	for _, p := range parts {
		if p == "" || p == "." || p == ".." {
			return bad
		}
	}
	switch {
	case len(parts) == 1:
		if id, ok := strings.CutSuffix(parts[0], ".jsonl"); ok && ValidSessionID(id) {
			return nil
		}
	case parts[0] == "memory":
		if len(parts) == 2 {
			return nil
		}
	case ValidSessionID(parts[0]) && parts[1] == "subagents":
		if len(parts) == 3 && (strings.HasSuffix(parts[2], ".jsonl") || strings.HasSuffix(parts[2], ".meta.json")) {
			return nil
		}
	case ValidSessionID(parts[0]) && parts[1] == "tool-results":
		if len(parts) >= 3 {
			return nil
		}
	}
	return bad
}
