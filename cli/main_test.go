package main

import (
	"bytes"
	"strings"
	"testing"

	"github.com/geocodio/yak/cli/internal/version"
)

func TestRunUnknownCommandReturns2(t *testing.T) {
	t.Setenv("YAK_HOME", t.TempDir())
	var out, errb bytes.Buffer
	code := run([]string{"nope"}, strings.NewReader(""), &out, &errb)
	if code != 2 || !strings.Contains(errb.String(), "Usage") {
		t.Fatalf("code %d, stderr %q", code, errb.String())
	}
}

func TestVersionCommand(t *testing.T) {
	t.Setenv("YAK_HOME", t.TempDir())
	var out, errb bytes.Buffer
	if code := run([]string{"version"}, strings.NewReader(""), &out, &errb); code != 0 {
		t.Fatalf("code %d", code)
	}
	if !strings.Contains(out.String(), version.Version) {
		t.Fatalf("stdout %q", out.String())
	}
}

func TestUserAgentMapsArch(t *testing.T) {
	if got := version.UserAgent("darwin", "amd64"); got != "yak-cli/0.1.0 (darwin; x86_64)" {
		t.Fatal(got)
	}
	if got := version.UserAgent("linux", "arm64"); got != "yak-cli/0.1.0 (linux; arm64)" {
		t.Fatal(got)
	}
}
