package cmd

import (
	"fmt"
	"os"

	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/session"
	"github.com/geocodio/yak/cli/internal/ui"
)

func init() { commands["config"] = runConfig }

const configUsage = "Usage: yak config [claude-config-dir <path> | claude-config-dir --unset]"

// runConfig shows the settings, or changes the Claude Code profile folder teleported sessions open in.
func runConfig(ctx *Context, args []string) int {
	cfg, err := config.Load(ctx.ConfigDir)
	if err != nil {
		ui.Fail(ctx.Stderr, "Could not read the yak config: %v", err)
		return 1
	}
	if len(args) == 0 {
		home := homeDir(ctx)
		setting := cfg.ClaudeConfigDir
		if setting == "" {
			setting = "(not set)"
		}
		fmt.Fprintf(ctx.Stdout, "claude-config-dir: %s\n", setting)
		fmt.Fprintf(ctx.Stdout, "  in use: %s\n", session.ClaudeConfigDir(ctx.Env, home, cfg.ClaudeConfigDir))
		return 0
	}
	if args[0] != "claude-config-dir" || len(args) != 2 {
		fmt.Fprintln(ctx.Stderr, configUsage)
		return 2
	}
	if args[1] == "--unset" {
		cfg.ClaudeConfigDir = ""
	} else {
		expanded := session.ClaudeConfigDir(func(string) string { return "" }, homeDir(ctx), args[1])
		if info, err := os.Stat(expanded); err != nil || !info.IsDir() {
			ui.Fail(ctx.Stderr, "%s is not an existing folder.", args[1])
			return 1
		}
		cfg.ClaudeConfigDir = args[1]
	}
	if err := cfg.Save(ctx.ConfigDir); err != nil {
		ui.Fail(ctx.Stderr, "Could not save the yak config: %v", err)
		return 1
	}
	ui.OK(ctx.Stdout, "Saved.")
	return 0
}

func homeDir(ctx *Context) string {
	if home := ctx.Env("HOME"); home != "" {
		return home
	}
	home, _ := os.UserHomeDir()
	return home
}
