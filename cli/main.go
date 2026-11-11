// Package main is the yak command line tool.
package main

import (
	"fmt"
	"io"
	"net/http"
	"os"
	"os/exec"
	"runtime"
	"syscall"
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
		Env:         os.Getenv,
		Keychain:    keychain.NewSystem(),
		Prompter:    prompter(stdin, stdout),
		OpenURL:     openURL,
		HTTP:        &http.Client{},
		Now:         time.Now,
		Sleep:       time.Sleep,
		Cwd:         cwd,
		Interactive: isTerminal(stdin),
		Exec:        execIn,
		ConfigDir:   dir,
	}, args)
}

func isTerminal(stdin io.Reader) bool {
	f, ok := stdin.(*os.File)
	return ok && ui.IsTTY(f)
}

// execIn replaces this process with argv, started in dir.
func execIn(dir string, argv []string) error {
	path, err := exec.LookPath(argv[0])
	if err != nil {
		return err
	}
	if err := os.Chdir(dir); err != nil {
		return err
	}
	return syscall.Exec(path, argv, os.Environ())
}

func prompter(stdin io.Reader, stdout io.Writer) ui.Prompter {
	if isTerminal(stdin) {
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
