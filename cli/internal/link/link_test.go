package link

import (
	"strings"
	"testing"
)

func TestValidThreadID(t *testing.T) {
	for _, ok := range []string{"1", "42", "9999999999"} {
		if !ValidThreadID(ok) {
			t.Errorf("%q should be valid", ok)
		}
	}
	for _, bad := range []string{"", "0", "042", "42abc", "-1", "4 2", "../x", "10000000000", "4\n2", "٤٢", "42\n"} {
		if ValidThreadID(bad) {
			t.Errorf("%q should be invalid", bad)
		}
	}
}

func TestParseAcceptsOnlyCanonicalForm(t *testing.T) {
	id, err := Parse("yak://teleport/42")
	if err != nil || id != 42 {
		t.Fatal(id, err)
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
	got := strings.Join(TerminalCommand(42), " ")
	if got != "yak teleport --from-link 42" {
		t.Fatal(got)
	}
	if TerminalCommand(-1) != nil || TerminalCommand(0) != nil {
		t.Fatal("accepted a bad id")
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
	if !strings.Contains(files["Contents/Info.plist"], "<key>CFBundleURLTypes</key>") ||
		!strings.Contains(files["Contents/Info.plist"], "<string>yak</string>") {
		t.Fatal("no url scheme")
	}
	if !strings.Contains(files["Contents/MacOS/yak-link"], `open-link "$1"`) {
		t.Fatal(files["Contents/MacOS/yak-link"])
	}
}

func TestInstallWritesFilesAndRegisters(t *testing.T) {
	home := t.TempDir()
	var calls []string
	run := func(name string, args ...string) error { calls = append(calls, name+" "+strings.Join(args, " ")); return nil }
	if _, err := Install("linux", home, "/bin/yak", run); err != nil || len(calls) != 1 || !strings.HasPrefix(calls[0], "xdg-mime default") {
		t.Fatal(err, calls)
	}
	if _, err := Install("darwin", home, "/bin/yak", run); err != nil || len(calls) != 2 || !strings.Contains(calls[1], "lsregister -f") {
		t.Fatal(err, calls)
	}
}
