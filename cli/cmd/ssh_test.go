package cmd

import (
	"strings"
	"testing"

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
	"target":                map[string]any{"host": "fix-cache-42", "port": 22, "user": "yak"},
	"host_key_fingerprints": []string{"SHA256:abc"},
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
	want := "ssh -J yak-jump@ssh.yak.test:2222 -o HostKeyAlias=fix-cache-42 -l yak fix-cache-42"
	if strings.Join(got, " ") != want {
		t.Fatalf("got %v", got)
	}
	if !strings.Contains(h.errb.String(), "SHA256:abc") {
		t.Fatal("no fingerprint note")
	}
}

func TestSSHConfigSnippet(t *testing.T) {
	h, srv := sshRig(t)
	srv.SSHBody = sshBody
	if code := Run(h.ctx, []string{"ssh", "--config", "42"}); code != 0 {
		t.Fatal(code)
	}
	for _, want := range []string{"Host fix-cache-42", "ProxyJump yak-jump@ssh.yak.test:2222", "User yak"} {
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
