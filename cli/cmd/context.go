// Package cmd holds one file per yak command.
package cmd

import (
	"fmt"
	"io"
	"net/http"
	"time"

	"github.com/geocodio/yak/cli/internal/keychain"
	"github.com/geocodio/yak/cli/internal/ui"
	"github.com/geocodio/yak/cli/internal/version"
)

// Context carries every side-effecting dependency so tests can replace them.
type Context struct {
	Stdin     io.Reader
	Stdout    io.Writer
	Stderr    io.Writer
	Env       func(string) string
	Keychain  keychain.Store
	HTTP      *http.Client
	Now       func() time.Time
	Sleep     func(time.Duration)
	Prompter  ui.Prompter
	OpenURL   func(string) error
	Cwd       string
	ConfigDir string
	// Interactive is true when stdin is a terminal and prompts may be shown.
	Interactive bool
	// Exec replaces this process with argv running in dir; nil means print the command instead.
	Exec func(dir string, argv []string) error
}

type command func(ctx *Context, args []string) int

var commands = map[string]command{}

// futureCommands are dispatched names whose implementation lands in a later chunk.
var futureCommands = []string{"ssh", "hook", "mcp", "open-link", "marker"}

func init() {
	commands["version"] = func(ctx *Context, _ []string) int {
		fmt.Fprintf(ctx.Stdout, "yak %s\n", version.Version)
		return 0
	}
	for _, name := range futureCommands {
		if _, ok := commands[name]; !ok {
			commands[name] = func(ctx *Context, _ []string) int {
				fmt.Fprintln(ctx.Stderr, "This command is not available yet.")
				return 1
			}
		}
	}
}

// Run dispatches args[0]; an unknown command prints usage and returns 2.
func Run(ctx *Context, args []string) int {
	if len(args) > 0 {
		if c, ok := commands[args[0]]; ok {
			return c(ctx, args[1:])
		}
	}
	fmt.Fprintln(ctx.Stderr, "Usage: yak <login|logout|whoami|teleport|ssh|hook|mcp|open-link|marker|version>")
	return 2
}
