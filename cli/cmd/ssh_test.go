package cmd

import (
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"

	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/fakeyak"
)

func sshRig(t *testing.T) (*harness, *fakeyak.Server) {
	t.Helper()
	h, srv, _, _ := linkRig(t)
	return h, srv
}

var sshBody = map[string]any{
	"jump_host":             map[string]any{"host": "ssh.yak.test", "port": 2222, "user": "yak-jump"},
	"alias":                 "fix-cache-42",
	"target":                map[string]any{"host": "127.18.4.17", "port": 22, "user": "yak"},
	"host_key_fingerprints": []string{},
}

func TestSSHStubAnswers501Copy(t *testing.T) {
	h, _ := sshRig(t)
	if code := Run(h.ctx, []string{"ssh", "42"}); code != 1 || !strings.Contains(h.errb.String(), "yak ssh is not available on this Yak server yet.") {
		t.Fatalf("%d %s", code, h.errb)
	}
}

func TestSSHOwnedByDevice(t *testing.T) {
	h, srv := sshRig(t)
	srv.Threads[42].OwnerSide = "device"
	if code := Run(h.ctx, []string{"ssh", "42"}); code != 1 || !strings.Contains(h.errb.String(), "Teleport it up first, preview, terminal and ssh are unavailable for a thread on your computer.") {
		t.Fatalf("%d %s", code, h.errb)
	}
}

func TestSSHNoKeyShowsSettingsURL(t *testing.T) {
	h, srv := sshRig(t)
	srv.SSHNoKeySettingsURL = "https://yak.test/settings/keys"
	if code := Run(h.ctx, []string{"ssh", "42"}); code != 1 || !strings.Contains(h.errb.String(), "https://yak.test/settings/keys") {
		t.Fatalf("%d %s", code, h.errb)
	}
}

func TestSSHBuildsJumpCommandFromContractBody(t *testing.T) {
	h, srv := sshRig(t)
	srv.SSHBody = sshBody
	var got []string
	h.ctx.Exec = func(_ string, argv []string) error { got = argv; return nil }
	if code := Run(h.ctx, []string{"ssh", "42"}); code != 0 {
		t.Fatalf("%d %s", code, h.errb)
	}
	want := "ssh -J yak-jump@ssh.yak.test:2222 -o HostKeyAlias=fix-cache-42 -o StrictHostKeyChecking=accept-new -o ConnectTimeout=60 -l yak 127.18.4.17"
	if strings.Join(got, " ") != want {
		t.Fatalf("got %v", got)
	}
}

func TestSSHPrintsFingerprintsWhenPresent(t *testing.T) {
	h, srv := sshRig(t)
	srv.SetSSH("42", fakeyak.SSHBody{
		"jump_host": map[string]any{"host": "j", "port": 2222, "user": "yak-jump"},
		"alias":     "a", "target": map[string]any{"host": "127.0.0.2", "port": 22, "user": "yak"},
		"host_key_fingerprints": []string{"SHA256:abc"},
	})
	if code := Run(h.ctx, []string{"ssh", "42"}); code != 0 || !strings.Contains(h.errb.String(), "SHA256:abc") {
		t.Fatalf("%d %s", code, h.errb)
	}
}

func TestSSHConfigSnippet(t *testing.T) {
	h, srv := sshRig(t)
	srv.SSHBody = sshBody
	if code := Run(h.ctx, []string{"ssh", "--config", "42"}); code != 0 {
		t.Fatal(code)
	}
	for _, want := range []string{"Host fix-cache-42", "ProxyJump yak-jump@ssh.yak.test:2222", "User yak", "HostName 127.18.4.17", "StrictHostKeyChecking accept-new", "ServerAliveInterval 15", "# Yak thread 42"} {
		if !strings.Contains(h.out.String(), want) {
			t.Fatalf("missing %q in %s", want, h.out)
		}
	}
}

func TestSSHBadThreadID(t *testing.T) {
	h, srv := sshRig(t)
	for _, bad := range [][]string{{"ssh"}, {"ssh", "abc"}, {"ssh", "042"}, {"ssh", "1;2"}} {
		if code := Run(h.ctx, bad); code != 2 {
			t.Errorf("%v exit %d", bad, code)
		}
	}
	if len(srv.Requests()) != 0 {
		t.Fatal("network used")
	}
}

func TestSSHConfigSnippetIsValidForOpenSSH(t *testing.T) {
	if _, err := exec.LookPath("ssh"); err != nil {
		t.Skip("no ssh")
	}
	h, srv := sshRig(t)
	srv.SSHBody = sshBody
	Run(h.ctx, []string{"ssh", "--config", "42"})
	file := filepath.Join(t.TempDir(), "config")
	if err := os.WriteFile(file, h.out.Bytes(), 0o600); err != nil {
		t.Fatal(err)
	}
	out, err := exec.Command("ssh", "-G", "-F", file, "fix-cache-42").CombinedOutput()
	if err != nil {
		t.Fatalf("%v %s", err, out)
	}
	for _, want := range []string{"proxyjump yak-jump@ssh.yak.test:2222", "hostkeyalias fix-cache-42"} {
		if !strings.Contains(string(out), want) {
			t.Fatalf("missing %q in %s", want, out)
		}
	}
}

func TestSSHDestroyedCopy(t *testing.T) {
	h, srv := sshRig(t)
	srv.SetSSHError("42", 409, "sandbox_destroyed", map[string]any{"thread_url": "https://yak.test/threads/42"})
	if code := Run(h.ctx, []string{"ssh", "42"}); code != 1 || !strings.Contains(h.errb.String(), "The sandbox was destroyed. Restore it from the thread page: https://yak.test/threads/42") {
		t.Fatalf("%d %s", code, h.errb)
	}
}

func TestSSHClosedCopy(t *testing.T) {
	h, srv := sshRig(t)
	srv.SetSSHError("42", 409, "thread_closed", nil)
	if code := Run(h.ctx, []string{"ssh", "42"}); code != 1 || !strings.Contains(h.errb.String(), "This thread is closed, its branch and PR carry the result.") {
		t.Fatalf("%d %s", code, h.errb)
	}
}

func TestSSHForbiddenCopy(t *testing.T) {
	h, srv := sshRig(t)
	srv.SetSSHError("42", 403, "forbidden", nil)
	if code := Run(h.ctx, []string{"ssh", "42"}); code != 1 || !strings.Contains(h.errb.String(), "You need driving rights on this thread to open a shell.") {
		t.Fatalf("%d %s", code, h.errb)
	}
}

func TestSSHPassesThroughExitCode(t *testing.T) {
	h, srv := sshRig(t)
	srv.SSHBody = sshBody
	h.ctx.Exec = func(string, []string) error { return exitCodeError(255) }
	if code := Run(h.ctx, []string{"ssh", "42"}); code != 255 {
		t.Fatalf("exit %d", code)
	}
}

func TestSSHNeverPrintsToken(t *testing.T) {
	h, srv := sshRig(t)
	srv.SSHBody = sshBody
	Run(h.ctx, []string{"ssh", "--config", "42"})
	Run(h.ctx, []string{"ssh", "42"})
	tok, err := h.ctx.Keychain.Get(srvURL(t, h))
	if err != nil || tok == "" {
		t.Fatalf("no token: %v", err)
	}
	if strings.Contains(h.out.String()+h.errb.String(), tok) {
		t.Fatal("token printed")
	}
}

type exitCodeError int

func (e exitCodeError) Error() string { return "exit" }
func (e exitCodeError) ExitCode() int { return int(e) }

func srvURL(t *testing.T, h *harness) string {
	t.Helper()
	cfg, err := config.Load(h.ctx.ConfigDir)
	if err != nil {
		t.Fatal(err)
	}
	return cfg.BaseURL
}
