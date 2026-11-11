package cmd

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"runtime"
	"strings"

	"github.com/geocodio/yak/cli/internal/api"
	"github.com/geocodio/yak/cli/internal/gitx"
	"github.com/geocodio/yak/cli/internal/link"
	"github.com/geocodio/yak/cli/internal/ui"
)

func init() { commands["open-link"] = openLink }

func openLink(ctx *Context, args []string) int {
	if len(args) == 1 && args[0] == "--install" {
		return installLink(ctx)
	}
	if len(args) != 1 {
		fmt.Fprintln(ctx.Stderr, "Usage: yak open-link <yak://teleport/<thread>> | --install")
		return 2
	}
	threadID, err := link.Parse(args[0])
	if err != nil {
		ui.Fail(ctx.Stderr, "That is not a Yak teleport link. Nothing was opened.")
		return 1
	}
	client, cfg, err := authedClient(ctx)
	if err != nil {
		return 1
	}
	var found api.ThreadResponse
	if err := client.Do(context.Background(), "GET", fmt.Sprintf("/threads/%d", threadID), nil, &found); err != nil {
		return reportAPIError(ctx, err)
	}
	repo := found.Thread.Repo
	dir := cfg.Checkouts[repo]
	if st, err := os.Stat(dir); dir == "" || err != nil || !st.IsDir() {
		dir, err = askCheckout(ctx, repo)
		if err != nil {
			ui.Fail(ctx.Stderr, "%v", err)
			return 1
		}
		if cfg.Checkouts == nil {
			cfg.Checkouts = map[string]string{}
		}
		cfg.Checkouts[repo] = dir
		if err := cfg.Save(ctx.ConfigDir); err != nil {
			ui.Warn(ctx.Stderr, "Could not remember that folder: %v", err)
		}
	}
	argv := link.TerminalCommand(threadID)
	if ctx.OpenTerminal == nil {
		fmt.Fprintf(ctx.Stdout, "Run: %s\n", link.ShellCommand(dir, argv))
		return 0
	}
	if err := ctx.OpenTerminal(dir, argv); err != nil {
		ui.Fail(ctx.Stderr, "Could not open a terminal: %v", err)
		fmt.Fprintf(ctx.Stderr, "  Run it yourself: %s\n", link.ShellCommand(dir, argv))
		return 1
	}
	return 0
}

func askCheckout(ctx *Context, repo string) (string, error) {
	home := ctx.Env("HOME")
	var roots []string
	for _, r := range []string{"code", "projects", "src"} {
		roots = append(roots, filepath.Join(home, r))
	}
	candidates := link.FindCheckouts(roots, repo, func(dir string) []string {
		r, _ := gitx.Repo{Dir: dir}.Remotes()
		return r
	})
	options := make([]ui.Option, 0, len(candidates)+1)
	for _, c := range candidates {
		options = append(options, ui.Option{Label: c})
	}
	options = append(options, ui.Option{Label: "Choose another folder"})
	i, err := ctx.Prompter.Choose(fmt.Sprintf("Where is %s on this computer?", repo), options, 0)
	if err != nil {
		return "", err
	}
	if i < len(candidates) {
		return candidates[i], nil
	}
	dir, err := ctx.Prompter.Edit("Folder", "")
	dir = strings.TrimSpace(dir)
	if st, statErr := os.Stat(dir); err != nil || dir == "" || statErr != nil || !st.IsDir() {
		return "", fmt.Errorf("Pick a folder that exists, then open the link again.")
	}
	return dir, nil
}

func installLink(ctx *Context) int {
	run := ctx.RunCommand
	if run == nil {
		run = link.SystemRunner
	}
	binary, err := os.Executable()
	if err == nil {
		_, err = link.Install(runtime.GOOS, ctx.Env("HOME"), binary, run)
	}
	if err != nil {
		fmt.Fprintln(ctx.Stdout, "Could not register the yak:// link. Use the command shown on the thread page.")
		return 0
	}
	ui.OK(ctx.Stdout, "yak:// links now open a terminal on this computer.")
	return 0
}
