// Package main is the yak command line tool.
package main

import (
	"fmt"
	"io"
	"net/http"
	"os"
	"time"

	"github.com/geocodio/yak/cli/cmd"
	"github.com/geocodio/yak/cli/internal/config"
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
		HTTP:      &http.Client{},
		Now:       time.Now,
		Sleep:     time.Sleep,
		Cwd:       cwd,
		ConfigDir: dir,
	}, args)
}

func main() {
	os.Exit(run(os.Args[1:], os.Stdin, os.Stdout, os.Stderr))
}
