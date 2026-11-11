package cmd

import (
	"fmt"
	"net/http"
	"os"
	"time"

	"github.com/geocodio/yak/cli/internal/api"
	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/hook"
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
	deps := hook.HookDeps{Binary: binary, ConfigDir: ctx.ConfigDir, Out: ctx.Stdout, API: func() (*api.Client, bool) { return silentClient(ctx) }}
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
