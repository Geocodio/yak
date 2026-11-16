// Package link handles yak:// links and the thread ids they carry.
package link

import (
	"errors"
	"path/filepath"
	"strings"
)

const publicIDAlphabet = "0123456789abcdefghjkmnpqrstvwxyz"

// ValidThreadID reports whether s names a thread: a public id (8 characters of lowercase Crockford base32, never
// all digits) or a legacy numeric id (1 to 10 digits, no leading zero). Nothing else passes.
func ValidThreadID(s string) bool {
	return validPublicID(s) || validNumericID(s)
}

func validNumericID(s string) bool {
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

func validPublicID(s string) bool {
	if len(s) != 8 {
		return false
	}
	allDigits := true
	for i := 0; i < len(s); i++ {
		if !strings.ContainsRune(publicIDAlphabet, rune(s[i])) {
			return false
		}
		if s[i] < '0' || s[i] > '9' {
			allDigits = false
		}
	}
	return !allDigits
}

const linkPrefix = "yak://teleport/"

// Parse accepts only the canonical form yak://teleport/<thread>. Anything else, including userinfo, a port, a
// query, a fragment, an extra path, percent-encoding, whitespace and control characters, is an error.
func Parse(raw string) (thread string, err error) {
	rest, ok := strings.CutPrefix(raw, linkPrefix)
	if !ok || !ValidThreadID(rest) {
		return "", errors.New("not a Yak teleport link")
	}
	return rest, nil
}

// TerminalCommand is the command the terminal runs. The thread is validated again, so a bad value yields nil.
func TerminalCommand(thread string) []string {
	if !ValidThreadID(thread) {
		return nil
	}
	return []string{"yak", "teleport", "--from-link", thread}
}

// TerminalCommandFor is TerminalCommand with the absolute path of the yak binary, so a terminal whose PATH lacks yak
// still runs it. A relative binary or an invalid thread yields nil.
func TerminalCommandFor(binary string, thread string) []string {
	argv := TerminalCommand(thread)
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

// URLTypesJSON is the CFBundleURLTypes value that makes an app claim the yak scheme.
const URLTypesJSON = `[{"CFBundleURLName":"Yak teleport link","CFBundleURLSchemes":["yak"]}]`

// RenderAppleScript returns the source of an applet whose open location handler runs `binary open-link <url>`.
// macOS delivers a clicked URL to an app as an Apple Event, never as an argument.
func RenderAppleScript(binary string) string {
	return "on open location theURL\n\tdo shell script quoted form of \"" + escapeAppleScript(binary) +
		"\" & \" open-link \" & quoted form of theURL\nend open location\n"
}

// RenderMacApp returns the inputs of the macOS handler: the applet source and the URL types to add to its Info.plist.
func RenderMacApp(binary string) map[string]string {
	return map[string]string{
		"handler.applescript": RenderAppleScript(binary),
		"url-types.json":      URLTypesJSON,
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
