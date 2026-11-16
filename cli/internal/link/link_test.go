package link

import (
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
)

func TestValidThreadID(t *testing.T) {
	for _, ok := range []string{"1", "42", "9999999999", "k7m2x9qd", "0a1b2c3d"} {
		if !ValidThreadID(ok) {
			t.Errorf("%q should be valid", ok)
		}
	}
	for _, bad := range []string{"", "0", "042", "42abc", "-1", "4 2", "../x", "10000000000", "4\n2", "٤٢", "42\n", "k7m2x9q", "k7m2x9qdd", "K7M2X9QD", "k7m2x9qi", "k7m2x9ql", "k7m2x9qo", "k7m2x9qu", "01234567", "k7m2-9qd"} {
		if ValidThreadID(bad) {
			t.Errorf("%q should be invalid", bad)
		}
	}
}

func TestParseAcceptsOnlyCanonicalForm(t *testing.T) {
	for _, want := range []string{"42", "k7m2x9qd"} {
		id, err := Parse("yak://teleport/" + want)
		if err != nil || id != want {
			t.Fatal(id, err)
		}
	}
}

func TestParseRejectsHostile(t *testing.T) {
	for _, raw := range []string{
		"yak://teleport/42/../43", "yak://teleport/42?x=1", "yak://teleport/4%32", "yak://teleport/042",
		"yak://user@teleport/42", "yak://teleport/42;rm", "yak://teleport/42\n", "yak://teleport/99999999999",
		"http://teleport/42", "", "yak://teleport/42#x", "yak://teleport:80/42", "yak://teleport/ 42",
	} {
		if _, err := Parse(raw); err == nil {
			t.Errorf("accepted %q", raw)
		}
	}
}

func TestTerminalCommandUsesValidatedIntegerOnly(t *testing.T) {
	got := strings.Join(TerminalCommand("42"), " ")
	if got != "yak teleport --from-link 42" {
		t.Fatal(got)
	}
	if TerminalCommand("-1") != nil || TerminalCommand("0") != nil {
		t.Fatal("accepted a bad id")
	}
}

func TestTerminalCommandForUsesAbsoluteBinary(t *testing.T) {
	got := strings.Join(TerminalCommandFor("/opt/yak/bin/yak", "k7m2x9qd"), " ")
	if got != "/opt/yak/bin/yak teleport --from-link k7m2x9qd" {
		t.Fatal(got)
	}
	if TerminalCommandFor("yak", "42") != nil || TerminalCommandFor("/opt/yak/bin/yak", "0") != nil {
		t.Fatal("accepted a relative binary or a bad id")
	}
}

func TestShellQuotingOfCheckoutPathWithSpacesAndQuotes(t *testing.T) {
	got := ShellCommand(`/Users/me/it's here; rm -rf`, []string{"yak", "teleport", "--from-link", "42"})
	want := `cd '/Users/me/it'\''s here; rm -rf' && 'yak' 'teleport' '--from-link' '42'`
	if got != want {
		t.Fatalf("got %s", got)
	}
}

func TestDesktopEntryExecIsOpenLink(t *testing.T) {
	e := RenderDesktopEntry("/usr/local/bin/yak")
	for _, want := range []string{"MimeType=x-scheme-handler/yak;", "Exec=/usr/local/bin/yak open-link %u"} {
		if !strings.Contains(e, want) {
			t.Errorf("missing %q", want)
		}
	}
}

func TestRenderMacAppHasURLScheme(t *testing.T) {
	files := RenderMacApp("/usr/local/bin/yak")
	if !strings.Contains(files["url-types.json"], `"CFBundleURLSchemes":["yak"]`) {
		t.Fatal(files["url-types.json"])
	}
	if files["handler.applescript"] != RenderAppleScript("/usr/local/bin/yak") {
		t.Fatal("applescript differs")
	}
}

func TestRenderAppleScriptHasOpenLocationHandler(t *testing.T) {
	s := RenderAppleScript("/usr/local/bin/yak")
	for _, want := range []string{"on open location theURL", `quoted form of "/usr/local/bin/yak"`, "quoted form of theURL"} {
		if !strings.Contains(s, want) {
			t.Errorf("missing %q in %s", want, s)
		}
	}
	if strings.Contains(s, "$1") {
		t.Fatal("reads an argument")
	}
}

func TestRenderAppleScriptEscapesQuotesInBinaryPath(t *testing.T) {
	s := RenderAppleScript(`/a"b\c/yak`)
	if !strings.Contains(s, `quoted form of "/a\"b\\c/yak"`) {
		t.Fatal(s)
	}
}

func TestInstallDarwinRunsOsacompileThenPlutilThenLsregister(t *testing.T) {
	home := t.TempDir()
	var calls []string
	run := func(name string, args ...string) error {
		calls = append(calls, name+" "+strings.Join(args, " "))
		return nil
	}
	app, err := Install("darwin", home, "/bin/yak", run)
	if err != nil || app != filepath.Join(home, "Applications", "Yak Link.app") {
		t.Fatal(app, err)
	}
	plist := filepath.Join(app, "Contents", "Info.plist")
	want := []string{
		"osacompile -o " + app + " ",
		"plutil -insert CFBundleURLTypes -json " + URLTypesJSON + " " + plist,
		"plutil -replace LSUIElement -bool true " + plist,
		"/System/Library/Frameworks/CoreServices.framework/Frameworks/LaunchServices.framework/Support/lsregister -f " + app,
	}
	if len(calls) != len(want) {
		t.Fatal(calls)
	}
	for i := range want {
		if !strings.HasPrefix(calls[i], want[i]) {
			t.Errorf("call %d: %q, want prefix %q", i, calls[i], want[i])
		}
	}
	if got, _ := os.ReadFile(RegisteredBinaryPath(home)); string(got) != "/bin/yak" {
		t.Fatalf("record %q", got)
	}
}

func TestInstalledAppleScriptCompiles(t *testing.T) {
	if runtime.GOOS != "darwin" {
		t.Skip("macOS only")
	}
	if _, err := exec.LookPath("osacompile"); err != nil {
		t.Skip("no osacompile")
	}
	home := t.TempDir()
	app, err := Install("darwin", home, `/tmp/a b"c/yak`, func(name string, args ...string) error {
		if name == "/System/Library/Frameworks/CoreServices.framework/Frameworks/LaunchServices.framework/Support/lsregister" {
			return nil // never register a throwaway app with the real LaunchServices
		}
		out, err := exec.Command(name, args...).CombinedOutput()
		if err != nil {
			t.Logf("%s: %s", name, out)
		}
		return err
	})
	if err != nil {
		t.Fatal(err)
	}
	if _, err := os.Stat(filepath.Join(app, "Contents", "Info.plist")); err != nil {
		t.Fatal(err)
	}
}

func TestInstallWritesFilesAndRegisters(t *testing.T) {
	home := t.TempDir()
	var calls []string
	run := func(name string, args ...string) error {
		calls = append(calls, name+" "+strings.Join(args, " "))
		return nil
	}
	if _, err := Install("linux", home, "/bin/yak", run); err != nil || len(calls) != 1 || !strings.HasPrefix(calls[0], "xdg-mime default") {
		t.Fatal(err, calls)
	}
	if _, err := Install("darwin", home, "/bin/yak", run); err != nil || !strings.Contains(calls[len(calls)-1], "lsregister -f") {
		t.Fatal(err, calls)
	}
}
