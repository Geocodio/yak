package plugincheck

import (
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

// shimEnv builds a temp plugin bin dir holding the shim, a stub uname and a stub binary for binName.
func shimEnv(t *testing.T, osName, machine, binName string) (shim string, env []string) {
	t.Helper()
	root := t.TempDir()
	binDir := filepath.Join(root, "plugin dir", "bin")
	stubs := filepath.Join(root, "stubs")
	for _, d := range []string{binDir, stubs} {
		if err := os.MkdirAll(d, 0o755); err != nil {
			t.Fatal(err)
		}
	}
	src, err := os.ReadFile(filepath.Join(pluginDir, "bin/yak"))
	if err != nil {
		t.Fatal(err)
	}
	shim = filepath.Join(binDir, "yak")
	write := func(path, body string) {
		if err := os.WriteFile(path, []byte(body), 0o755); err != nil {
			t.Fatal(err)
		}
	}
	write(shim, string(src))
	write(filepath.Join(stubs, "uname"), "#!/bin/sh\nif [ \"$1\" = -m ]; then echo '"+machine+"'; else echo '"+osName+"'; fi\n")
	if binName != "" {
		write(filepath.Join(binDir, binName), "#!/bin/sh\nfor a in \"$@\"; do echo \"[$a]\"; done\n")
	}
	return shim, append(os.Environ(), "PATH="+stubs+":"+os.Getenv("PATH"))
}

func runShim(t *testing.T, shim string, env []string, args ...string) (string, error) {
	t.Helper()
	cmd := exec.Command("sh", append([]string{shim}, args...)...)
	cmd.Env = env
	out, err := cmd.CombinedOutput()
	return string(out), err
}

func TestShimPicksBinaryPerPlatform(t *testing.T) {
	for _, c := range []struct{ os, machine, bin string }{
		{"Darwin", "arm64", "yak-darwin-arm64"},
		{"Darwin", "x86_64", "yak-darwin-x86_64"},
		{"Linux", "x86_64", "yak-linux-x86_64"},
		{"Linux", "amd64", "yak-linux-x86_64"},
	} {
		shim, env := shimEnv(t, c.os, c.machine, c.bin)
		out, err := runShim(t, shim, env, "whoami")
		if err != nil || out != "[whoami]\n" {
			t.Errorf("%s/%s: %q %v", c.os, c.machine, out, err)
		}
	}
}

func TestShimRejectsUnsupported(t *testing.T) {
	for _, c := range []struct{ os, machine string }{{"FreeBSD", "x86_64"}, {"Linux", "aarch64"}, {"Darwin", "ppc"}} {
		shim, env := shimEnv(t, c.os, c.machine, "")
		out, err := runShim(t, shim, env)
		if err == nil || !strings.Contains(out, "yak does not support") {
			t.Errorf("%s/%s: %q %v", c.os, c.machine, out, err)
		}
	}
}

func TestShimMissingBinaryWithoutManifest(t *testing.T) {
	shim, env := shimEnv(t, "Darwin", "arm64", "")
	out, err := runShim(t, shim, env)
	if err == nil || !strings.Contains(out, "Cannot read the plugin version") {
		t.Fatalf("%q %v", out, err)
	}
}

func TestShimMissingBinaryDownloadFails(t *testing.T) {
	shim, env := shimEnv(t, "Darwin", "arm64", "")
	binDir := filepath.Dir(shim)
	manifestDir := filepath.Join(filepath.Dir(binDir), ".claude-plugin")
	if err := os.MkdirAll(manifestDir, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(manifestDir, "plugin.json"), []byte(`{"name": "yak", "version": "9.9.9"}`), 0o644); err != nil {
		t.Fatal(err)
	}
	stubs := filepath.Join(filepath.Dir(filepath.Dir(binDir)), "stubs")
	if err := os.WriteFile(filepath.Join(stubs, "curl"), []byte("#!/bin/sh\nexit 22\n"), 0o755); err != nil {
		t.Fatal(err)
	}
	out, err := runShim(t, shim, env)
	if err == nil || !strings.Contains(out, "Could not download yak 9.9.9") {
		t.Fatalf("%q %v", out, err)
	}
	entries, _ := os.ReadDir(binDir)
	if len(entries) != 1 {
		t.Fatalf("download left files behind: %v", entries)
	}
}

func TestShimQuotesArguments(t *testing.T) {
	shim, env := shimEnv(t, "Darwin", "arm64", "yak-darwin-arm64")
	out, err := runShim(t, shim, env, "two words", "--flag", "-x")
	if err != nil || out != "[two words]\n[--flag]\n[-x]\n" {
		t.Fatalf("%q %v", out, err)
	}
}

func TestShimFollowsSymlink(t *testing.T) {
	shim, env := shimEnv(t, "Linux", "x86_64", "yak-linux-x86_64")
	link := filepath.Join(t.TempDir(), "yak")
	if err := os.Symlink(shim, link); err != nil {
		t.Fatal(err)
	}
	out, err := runShim(t, link, env, "a")
	if err != nil || out != "[a]\n" {
		t.Fatalf("%q %v", out, err)
	}
}
