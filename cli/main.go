// Package main is the yak command line tool.
package main

import (
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"runtime"
	"time"

	"github.com/geocodio/yak/cli/cmd"
	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/keychain"
	"github.com/geocodio/yak/cli/internal/ui"
)

func run(args []string, stdin io.Reader, stdout, stderr io.Writer) int {
	dir, err := config.Dir()
	if err != nil {
		fmt.Fprintln(stderr, err)
		return 1
	}
	cwd, _ := os.Getwd()
	return cmd.Run(&cmd.Context{
		Stdin: stdin, Stdout: stdout, Stderr: stderr,
		Env:       os.Getenv,
		Keychain:  keychain.NewSystem(),
		Prompter:  prompter(stdin, stdout),
		OpenURL:   openURL,
		HTTP:      &http.Client{},
		Now:       time.Now,
		Sleep:     time.Sleep,
		Cwd:       cwd,
		ConfigDir: dir,
	}, args)
}

func prompter(stdin io.Reader, stdout io.Writer) ui.Prompter {
	if f, ok := stdin.(*os.File); ok && ui.IsTTY(f) {
		return ui.NewTerminal(stdin, stdout)
	}
	return ui.NewNonInteractive()
}

func openURL(u string) error {
	name := "xdg-open"
	if runtime.GOOS == "darwin" {
		name = "open"
	}
	return exec.Command(name, u).Start()
}

func main() {
	os.Exit(run(os.Args[1:], os.Stdin, os.Stdout, os.Stderr))
}
