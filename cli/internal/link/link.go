// Package link handles yak:// links and the thread ids they carry.
package link

import (
	"errors"
	"path/filepath"
	"strconv"
	"strings"
)

// ValidThreadID reports whether s is a numeric thread id: 1 to 10 digits, no leading zero, nothing else.
func ValidThreadID(s string) bool {
	if len(s) == 0 || len(s) > 10 || s[0] == '0' {
		return false
	}
	for i := 0; i < len(s); i++ {
		if s[i] < '0' || s[i] > '9' {
			return false
		}
	}
	return true
}

const linkPrefix = "yak://teleport/"

// Parse accepts only the canonical form yak://teleport/<id>. Anything else, including userinfo, a port, a
// query, a fragment, an extra path, percent-encoding, whitespace and control characters, is an error.
func Parse(raw string) (threadID int, err error) {
	rest, ok := strings.CutPrefix(raw, linkPrefix)
	if !ok || !ValidThreadID(rest) {
		return 0, errors.New("not a Yak teleport link")
	}
	return strconv.Atoi(rest)
}

// TerminalCommand is the command the terminal runs. The id is validated again, so a bad value yields nil.
func TerminalCommand(threadID int) []string {
	id := strconv.Itoa(threadID)
	if !ValidThreadID(id) {
		return nil
	}
	return []string{"yak", "teleport", "--from-link", id}
}

// TerminalCommandFor is TerminalCommand with the absolute path of the yak binary, so a terminal whose PATH lacks yak
// still runs it. A relative binary or an invalid id yields nil.
func TerminalCommandFor(binary string, threadID int) []string {
	argv := TerminalCommand(threadID)
	if argv == nil || !filepath.IsAbs(binary) {
		return nil
	}
	argv[0] = binary
	return argv
}

// ShellQuote wraps s in single quotes for a POSIX shell.
func ShellQuote(s string) string { return "'" + strings.ReplaceAll(s, "'", `'\''`) + "'" }

// ShellCommand builds `cd <dir> && <argv...>` with every part quoted.
func ShellCommand(dir string, argv []string) string {
	parts := make([]string, len(argv))
	for i, a := range argv {
		parts[i] = ShellQuote(a)
	}
	return "cd " + ShellQuote(dir) + " && " + strings.Join(parts, " ")
}

// RenderMacApp returns the files of a macOS app bundle, keyed by path inside the bundle, that claims the yak scheme.
func RenderMacApp(binary string) map[string]string {
	return map[string]string{
		"Contents/Info.plist": `<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
	<key>CFBundleName</key><string>Yak Link</string>
	<key>CFBundleIdentifier</key><string>build.yak.link</string>
	<key>CFBundleExecutable</key><string>yak-link</string>
	<key>CFBundlePackageType</key><string>APPL</string>
	<key>LSUIElement</key><true/>
	<key>CFBundleURLTypes</key>
	<array>
		<dict>
			<key>CFBundleURLName</key><string>Yak teleport link</string>
			<key>CFBundleURLSchemes</key>
			<array><string>yak</string></array>
		</dict>
	</array>
</dict>
</plist>
`,
		"Contents/MacOS/yak-link": "#!/bin/sh\nexec " + ShellQuote(binary) + " open-link \"$1\"\n",
	}
}

// RenderDesktopEntry returns yak-handler.desktop for Linux.
func RenderDesktopEntry(binary string) string {
	exec := binary
	if strings.ContainsAny(binary, " \t\"'\\$`") {
		exec = `"` + strings.NewReplacer(`\`, `\\`, `"`, `\"`, "$", `\$`, "`", "\\`").Replace(binary) + `"`
	}
	return "[Desktop Entry]\nType=Application\nName=Yak Link\nNoDisplay=true\nTerminal=false\n" +
		"Exec=" + exec + " open-link %u\nMimeType=x-scheme-handler/yak;\n"
}

// RepoSlug reduces a git remote URL to owner/name.
func RepoSlug(remote string) string {
	r := strings.TrimSuffix(strings.TrimSpace(remote), ".git")
	if i := strings.Index(r, "://"); i >= 0 {
		r = r[i+3:]
		r = r[strings.Index(r+"/", "/")+1:]
	} else if i := strings.Index(r, ":"); i >= 0 {
		r = r[i+1:]
	}
	parts := strings.Split(strings.Trim(r, "/"), "/")
	if len(parts) > 2 {
		parts = parts[len(parts)-2:]
	}
	return strings.Join(parts, "/")
}
