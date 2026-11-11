package cmd

import (
	"bytes"
	"fmt"
	"net/http"
	"os"
	"runtime"
	"strings"
	"time"

	"github.com/geocodio/yak/cli/internal/api"
	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/hook"
	"github.com/geocodio/yak/cli/internal/link"
)

func init() { commands["hook"] = runHook }

// runHook always exits 0: a hook must never fail a prompt.
func runHook(ctx *Context, args []string) int {
	if len(args) != 1 {
		return 0
	}
	in, ok := hook.ReadInput(ctx.Stdin)
	if !ok {
		return 0
	}
	binary, _ := os.Executable()
	home := ctx.Env("HOME")
	refresh := func() {
		run := ctx.RunCommand
		if run == nil {
			run = link.SystemRunner
		}
		_, _ = link.Install(runtime.GOOS, home, binary, run)
	}
	deps := hook.HookDeps{Binary: binary, Home: home, RefreshHandler: refresh, ConfigDir: ctx.ConfigDir, Out: ctx.Stdout, API: func() (*api.Client, bool) { return silentClient(ctx) }}
	deps.RunTeleport = func(args []string) (string, int) {
		var buf bytes.Buffer
		inner := *ctx
		inner.Stdout, inner.Stderr, inner.Interactive = &buf, &buf, false
		inner.Stdin = strings.NewReader("")
		if in.Cwd != "" {
			inner.Cwd = in.Cwd
		}
		code := runTeleport(&inner, append([]string{"--session", in.SessionID}, args...))
		return buf.String(), code
	}
	var out string
	switch args[0] {
	case "session-start":
		out = hook.SessionStart(deps, in)
	case "user-prompt-submit":
		out = hook.UserPromptSubmit(deps, in)
	}
	fmt.Fprint(ctx.Stdout, out)
	return 0
}

// silentClient builds the signed-in client without printing anything.
func silentClient(ctx *Context) (*api.Client, bool) {
	cfg, err := config.Load(ctx.ConfigDir)
	if err != nil || cfg.BaseURL == "" {
		return nil, false
	}
	token, err := ctx.Keychain.Get(cfg.BaseURL)
	if err != nil {
		return nil, false
	}
	hc := ctx.HTTP
	if hc == nil {
		hc = &http.Client{}
	}
	hc2 := *hc
	hc2.Timeout = 3 * time.Second
	return api.New(cfg.BaseURL, token, &hc2, userAgent()), true
}
