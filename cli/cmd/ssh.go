package cmd

import (
	"context"
	"errors"
	"fmt"
	"strings"

	"github.com/geocodio/yak/cli/internal/api"
	"github.com/geocodio/yak/cli/internal/link"
	"github.com/geocodio/yak/cli/internal/ui"
)

func init() { commands["ssh"] = runSSH }

type sshTarget struct {
	JumpHost struct {
		Host string `json:"host"`
		Port int    `json:"port"`
		User string `json:"user"`
	} `json:"jump_host"`
	Alias  string `json:"alias"`
	Target struct {
		Host string `json:"host"`
		Port int    `json:"port"`
		User string `json:"user"`
	} `json:"target"`
	HostKeyFingerprints []string `json:"host_key_fingerprints"`
}

func (t sshTarget) jump() string {
	return fmt.Sprintf("%s@%s:%d", t.JumpHost.User, t.JumpHost.Host, t.JumpHost.Port)
}

func (t sshTarget) argv() []string {
	argv := []string{"ssh", "-J", t.jump(), "-o", "HostKeyAlias=" + t.Alias,
		"-o", "StrictHostKeyChecking=accept-new", "-o", "ConnectTimeout=60", "-l", t.Target.User}
	if t.Target.Port != 0 && t.Target.Port != 22 {
		argv = append(argv, "-p", fmt.Sprint(t.Target.Port))
	}
	return append(argv, t.Target.Host)
}

func (t sshTarget) configSnippet(thread string) string {
	var b strings.Builder
	fmt.Fprintf(&b, "# Yak thread %s\nHost %s\n  HostName %s\n  User %s\n", thread, t.Alias, t.Target.Host, t.Target.User)
	if t.Target.Port != 0 && t.Target.Port != 22 {
		fmt.Fprintf(&b, "  Port %d\n", t.Target.Port)
	}
	fmt.Fprintf(&b, "  ProxyJump %s\n  HostKeyAlias %s\n  StrictHostKeyChecking accept-new\n  ServerAliveInterval 15\n", t.jump(), t.Alias)
	return b.String()
}

func runSSH(ctx *Context, args []string) int {
	var thread string
	snippet := false
	for _, a := range args {
		switch {
		case a == "--config":
			snippet = true
		case thread == "" && !strings.HasPrefix(a, "-"):
			thread = a
		default:
			fmt.Fprintln(ctx.Stderr, "Usage: yak ssh [--config] <thread>")
			return 2
		}
	}
	if !link.ValidThreadID(thread) {
		fmt.Fprintln(ctx.Stderr, "Usage: yak ssh [--config] <thread>")
		return 2
	}
	client, _, err := authedClient(ctx)
	if err != nil {
		return 1
	}
	var target sshTarget
	if err := client.Do(context.Background(), "GET", "/threads/"+thread+"/ssh", nil, &target); err != nil {
		return reportSSHError(ctx, err)
	}
	if len(target.HostKeyFingerprints) > 0 {
		fmt.Fprintf(ctx.Stderr, "Host key fingerprints Yak expects for %s: %s\n", target.Alias, strings.Join(target.HostKeyFingerprints, ", "))
	}
	if snippet {
		fmt.Fprint(ctx.Stdout, target.configSnippet(thread))
		return 0
	}
	if ctx.Exec == nil {
		fmt.Fprintf(ctx.Stdout, "Run: %s\n", strings.Join(target.argv(), " "))
		return 0
	}
	if err := ctx.Exec(ctx.Cwd, target.argv()); err != nil {
		var exited interface{ ExitCode() int }
		if errors.As(err, &exited) {
			return exited.ExitCode()
		}
		ui.Fail(ctx.Stderr, "Could not start ssh: %v", err)
		return 1
	}
	return 0
}

func reportSSHError(ctx *Context, err error) int {
	switch {
	case api.IsCode(err, "not_implemented"):
		ui.Fail(ctx.Stderr, "yak ssh is not available on this Yak server yet.")
	case api.IsCode(err, "thread_owned_by_device"):
		ui.Fail(ctx.Stderr, "Teleport it up first, preview, terminal and ssh are unavailable for a thread on your computer.")
	case api.IsCode(err, "sandbox_destroyed"):
		e, _ := api.AsError(err)
		url, _ := e.Details["thread_url"].(string)
		ui.Fail(ctx.Stderr, "The sandbox was destroyed. Restore it from the thread page: %s", url)
	case api.IsCode(err, "thread_closed"):
		ui.Fail(ctx.Stderr, "This thread is closed, its branch and PR carry the result.")
	case api.IsCode(err, "forbidden"):
		ui.Fail(ctx.Stderr, "You need driving rights on this thread to open a shell.")
	case api.IsCode(err, "no_ssh_key"):
		e, _ := api.AsError(err)
		url, _ := e.Details["settings_url"].(string)
		ui.Fail(ctx.Stderr, "Yak has no SSH key for you yet. Add one at %s", url)
	default:
		return reportAPIError(ctx, err)
	}
	return 1
}
