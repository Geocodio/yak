package link

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"
)

// Runner runs an external command.
type Runner func(name string, args ...string) error

// SystemRunner runs the command for real.
func SystemRunner(name string, args ...string) error { return exec.Command(name, args...).Run() }

// Install writes the yak:// handler for goos under home and registers it. It returns the handler path.
func Install(goos, home, binary string, run Runner) (string, error) {
	switch goos {
	case "darwin":
		app := filepath.Join(home, "Applications", "Yak Link.app")
		source := filepath.Join(home, ".config", "yak", "handler.applescript")
		if err := writeFile(source, RenderAppleScript(binary), 0o644); err != nil {
			return "", err
		}
		if err := os.MkdirAll(filepath.Dir(app), 0o755); err != nil {
			return "", err
		}
		if err := os.RemoveAll(app); err != nil {
			return "", err
		}
		plist := filepath.Join(app, "Contents", "Info.plist")
		const lsregister = "/System/Library/Frameworks/CoreServices.framework/Frameworks/LaunchServices.framework/Support/lsregister"
		steps := [][]string{
			{"osacompile", "-o", app, source},
			{"plutil", "-insert", "CFBundleURLTypes", "-json", URLTypesJSON, plist},
			{"plutil", "-replace", "LSUIElement", "-bool", "true", plist},
			{lsregister, "-f", app},
		}
		for _, step := range steps {
			if err := run(step[0], step[1:]...); err != nil {
				return "", err
			}
		}
		return app, writeFile(RegisteredBinaryPath(home), binary, 0o644)
	case "linux":
		path := filepath.Join(home, ".local", "share", "applications", "yak-handler.desktop")
		if err := writeFile(path, RenderDesktopEntry(binary), 0o644); err != nil {
			return "", err
		}
		if err := run("xdg-mime", "default", "yak-handler.desktop", "x-scheme-handler/yak"); err != nil {
			return path, err
		}
		return path, writeFile(RegisteredBinaryPath(home), binary, 0o644)
	}
	return "", fmt.Errorf("yak:// links are not supported on %s", goos)
}

func writeFile(path, content string, mode os.FileMode) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return err
	}
	return os.WriteFile(path, []byte(content), mode)
}

// FindCheckouts lists folders one level below each root whose git remote is the given owner/name slug.
func FindCheckouts(roots []string, slug string, remotes func(dir string) []string) []string {
	var found []string
	for _, root := range roots {
		entries, err := os.ReadDir(root)
		if err != nil {
			continue
		}
		for _, e := range entries {
			dir := filepath.Join(root, e.Name())
			if _, err := os.Stat(filepath.Join(dir, ".git")); err != nil {
				continue
			}
			for _, r := range remotes(dir) {
				if RepoSlug(r) == slug {
					found = append(found, dir)
					break
				}
			}
		}
	}
	sort.Strings(found)
	return found
}

// OpenTerminalSystem opens a terminal window running argv in dir (macOS Terminal, or the first Linux terminal found).
func OpenTerminalSystem(goos string) func(dir string, argv []string) error {
	return func(dir string, argv []string) error {
		shell := ShellCommand(dir, argv)
		if goos == "darwin" {
			script := `tell application "Terminal"` + "\nactivate\ndo script \"" +
				escapeAppleScript(shell) + "\"\nend tell"
			return exec.Command("osascript", "-e", script).Run()
		}
		for _, term := range [][]string{{"x-terminal-emulator", "-e"}, {"gnome-terminal", "--"}, {"konsole", "-e"}} {
			if path, err := exec.LookPath(term[0]); err == nil {
				args := append(term[1:], "sh", "-c", shell)
				return exec.Command(path, args...).Start()
			}
		}
		return fmt.Errorf("no terminal program found")
	}
}

func escapeAppleScript(s string) string {
	out := make([]byte, 0, len(s))
	for i := 0; i < len(s); i++ {
		if s[i] == '\\' || s[i] == '"' {
			out = append(out, '\\')
		}
		out = append(out, s[i])
	}
	return string(out)
}

// RegisteredBinaryPath is the file that records which yak binary the handler runs.
func RegisteredBinaryPath(home string) string {
	return filepath.Join(home, ".config", "yak", "handler-binary")
}

// RegisteredBinary returns the yak binary the installed handler runs, or "" when no handler was installed.
func RegisteredBinary(home string) string {
	b, err := os.ReadFile(RegisteredBinaryPath(home))
	if err != nil {
		return ""
	}
	return strings.TrimSpace(string(b))
}
