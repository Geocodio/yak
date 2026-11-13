package cmd

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"io"
	"os"
	"os/signal"
	"strings"

	"github.com/geocodio/yak/cli/internal/api"
	"github.com/geocodio/yak/cli/internal/link"
	"github.com/geocodio/yak/cli/internal/slash"
	"github.com/geocodio/yak/cli/internal/teleport"
	"github.com/geocodio/yak/cli/internal/ui"
	"github.com/geocodio/yak/cli/internal/version"
)

// Exit codes of yak teleport.
const (
	exitOK      = 0
	exitFailure = 1
	exitUsage   = 2
	exitRefused = 3
)

const teleportUsage = `Usage: yak teleport [<thread>] [options]

  yak teleport              move the current Claude session up to Yak
  yak teleport <thread>     bring a thread down to this checkout

Up options:   --session <id>  --message <text>  --add-dir <path>  --include-secrets
Down options: --wait | --stop  --keep | --private  --stash | --worktree
Both:         --yes (answer every question with its default)`

type stringList []string

func (s *stringList) String() string     { return strings.Join(*s, ",") }
func (s *stringList) Set(v string) error { *s = append(*s, v); return nil }

type teleportFlags struct {
	session, message                                string
	addDirs                                         stringList
	includeSecrets                                  bool
	wait, stop, keep, private, stash, worktree, yes bool
	fromLink                                        bool
	thread                                          string
}

func init() {
	commands["teleport"] = runTeleport
	commands["slash-teleport"] = runSlashTeleport
}

// runSlashTeleport runs teleport for the raw text after /yak.
func runSlashTeleport(ctx *Context, args []string) int {
	argv, err := slash.TeleportArgs(strings.Join(args, " "))
	if err != nil {
		return usageError(ctx, err.Error())
	}
	// A slash command has no terminal for questions, so every prompt takes its default without being shown.
	// The flow stays interactive so the defaults match a terminal run: tracked and untracked changes travel.
	ctx.Prompter, ctx.Interactive = ui.NewNonInteractive(), true
	return runTeleport(ctx, argv)
}

// parseTeleportArgs accepts flags before and after the thread argument.
func parseTeleportArgs(args []string) (teleportFlags, []string, error) {
	var f teleportFlags
	fs := flag.NewFlagSet("teleport", flag.ContinueOnError)
	fs.SetOutput(io.Discard)
	fs.StringVar(&f.session, "session", "", "")
	fs.StringVar(&f.message, "message", "", "")
	fs.StringVar(&f.message, "m", "", "")
	fs.Var(&f.addDirs, "add-dir", "")
	fs.BoolVar(&f.includeSecrets, "include-secrets", false, "")
	fs.BoolVar(&f.wait, "wait", false, "")
	fs.BoolVar(&f.stop, "stop", false, "")
	fs.BoolVar(&f.keep, "keep", false, "")
	fs.BoolVar(&f.private, "private", false, "")
	fs.BoolVar(&f.stash, "stash", false, "")
	fs.BoolVar(&f.worktree, "worktree", false, "")
	fs.BoolVar(&f.yes, "yes", false, "")
	fs.BoolVar(&f.fromLink, "from-link", false, "")
	var positional []string
	for {
		if err := fs.Parse(args); err != nil {
			return f, nil, err
		}
		if fs.NArg() == 0 {
			break
		}
		positional = append(positional, fs.Arg(0))
		args = fs.Args()[1:]
	}
	return f, positional, nil
}

func usageError(ctx *Context, why string) int {
	if why != "" {
		ui.Fail(ctx.Stderr, "%s", why)
	}
	fmt.Fprintln(ctx.Stderr, teleportUsage)
	return exitUsage
}

func runTeleport(ctx *Context, args []string) int {
	f, positional, err := parseTeleportArgs(args)
	if err != nil {
		return usageError(ctx, err.Error())
	}
	if len(positional) > 1 {
		return usageError(ctx, "Give one thread id at most.")
	}
	if len(positional) == 1 {
		if !link.ValidThreadID(positional[0]) {
			return usageError(ctx, fmt.Sprintf("%q is not a thread id. A thread id looks like k7m2x9qd.", positional[0]))
		}
		f.thread = positional[0]
	}
	for _, pair := range [][2]bool{{f.wait, f.stop}, {f.keep, f.private}, {f.stash, f.worktree}} {
		if pair[0] && pair[1] {
			return usageError(ctx, "Those options cannot be combined.")
		}
	}

	client, cfg, err := authedClient(ctx)
	if err != nil {
		return exitFailure
	}
	var me api.Me
	if err := client.Do(context.Background(), "GET", "/me", nil, &me); err != nil {
		return reportAPIError(ctx, err)
	}
	if me.Protocol.Min > version.ProtocolVersion {
		printTooOld(ctx)
		return exitFailure
	}
	prompter := ctx.Prompter
	if f.yes && !f.fromLink {
		prompter = ui.NewNonInteractive()
	}
	home := ctx.Env("HOME")
	if home == "" {
		home, _ = os.UserHomeDir()
	}
	deps := teleport.Deps{
		API: client, Prompter: prompter, Out: ctx.Stdout, Config: cfg, ConfigDir: ctx.ConfigDir,
		Now: ctx.Now, Sleep: ctx.Sleep, Env: ctx.Env, Home: home,
	}
	bg, stop := signal.NotifyContext(context.Background(), os.Interrupt)
	defer stop()

	if f.thread == "" {
		return teleportUp(bg, ctx, deps, f)
	}
	return teleportDown(bg, ctx, deps, f, f.thread)
}

func teleportUp(bg context.Context, ctx *Context, deps teleport.Deps, f teleportFlags) int {
	if f.includeSecrets {
		yes, err := ctx.Prompter.Confirm("Include files that look like secrets in the teleport? Their contents go to Yak.", false)
		if err != nil || !yes {
			ui.Fail(ctx.Stderr, "Not including secret files. Run again without --include-secrets.")
			return exitRefused
		}
	}
	_, err := teleport.Up(bg, deps, teleport.UpInput{
		Cwd: ctx.Cwd, SessionID: f.session, Message: f.message, AddDirs: f.addDirs,
		Options: teleport.PrepareOptions{
			Interactive: ctx.Interactive && !f.yes, IncludeSecrets: f.includeSecrets,
		},
	})
	if err != nil {
		return reportTeleportError(ctx, err)
	}
	return exitOK
}

func teleportDown(bg context.Context, ctx *Context, deps teleport.Deps, f teleportFlags, thread string) int {
	opts := teleport.DownOptions{FromLink: f.fromLink}
	switch {
	case f.wait:
		opts.RunningTurn = "wait"
	case f.stop:
		opts.RunningTurn = "stop"
	}
	switch {
	case f.keep:
		opts.Visibility = "keep"
	case f.private:
		opts.Visibility = "private"
	}
	switch {
	case f.stash:
		opts.Resolution = "stash"
	case f.worktree:
		opts.Resolution = "worktree"
	}
	res, err := teleport.Down(bg, deps, teleport.DownInput{ThreadRef: thread, Cwd: ctx.Cwd, Options: opts})
	if err != nil {
		return reportTeleportError(ctx, err)
	}
	argv := []string{"claude", "--resume", res.SessionID}
	if ctx.Exec != nil {
		fmt.Fprintf(ctx.Stdout, "Starting claude --resume %s in %s\n", res.SessionID, res.ResumeDir)
		if err := ctx.Exec(res.ResumeDir, argv); err != nil {
			ui.Fail(ctx.Stderr, "Could not start claude: %v", err)
			fmt.Fprintf(ctx.Stderr, "  Run it yourself: cd %s && claude --resume %s\n", res.ResumeDir, res.SessionID)
			return exitFailure
		}
		return exitOK
	}
	fmt.Fprintf(ctx.Stdout, "Continue with: cd %s && claude --resume %s\n", res.ResumeDir, res.SessionID)
	return exitOK
}

func reportTeleportError(ctx *Context, err error) int {
	var refusal *teleport.Refusal
	var userErr *teleport.UserError
	switch {
	case errors.Is(err, context.Canceled):
		fmt.Fprintln(ctx.Stderr, "Cancelled. Yak keeps the thread.")
		return exitFailure
	case errors.As(err, &refusal):
		ui.Fail(ctx.Stderr, "%s", refusal.Error())
		if refusal.Kind == "declined" {
			return exitFailure
		}
		return exitRefused
	}
	if e, ok := api.AsError(err); ok {
		if api.IsCode(err, "protocol_too_old") || !errors.As(err, &userErr) {
			return reportAPIError(ctx, e)
		}
		if api.IsCode(err, "device_revoked") || api.IsCode(err, "invalid_token") {
			return reportAPIError(ctx, e)
		}
	}
	ui.Fail(ctx.Stderr, "%s", err.Error())
	return exitFailure
}
