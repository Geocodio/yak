package cmd

import (
	"encoding/json"
	"fmt"

	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/session"
	"github.com/geocodio/yak/cli/internal/ui"
)

func init() { commands["marker"] = runMarker }

// runMarker is how a person clears or inspects the read-only marker of a session, for example after a revoked device.
func runMarker(ctx *Context, args []string) int {
	if len(args) != 2 || (args[0] != "clear" && args[0] != "show") {
		fmt.Fprintln(ctx.Stderr, "Usage: yak marker clear|show <session-id>")
		return 2
	}
	sessionID := args[1]
	if !session.ValidSessionID(sessionID) {
		ui.Fail(ctx.Stderr, "%q is not a session id. Session ids look like 4f1c0c9e-7b53-4a52-9f7d-1c2b3a4d5e6f.", sessionID)
		return 2
	}
	if args[0] == "show" {
		m, ok := config.ReadMarker(ctx.ConfigDir, sessionID)
		if !ok {
			fmt.Fprintln(ctx.Stdout, "No marker for that session.")
			return 0
		}
		b, _ := json.MarshalIndent(m, "", "  ")
		fmt.Fprintln(ctx.Stdout, string(b))
		return 0
	}
	if err := config.ClearMarker(ctx.ConfigDir, sessionID); err != nil {
		ui.Fail(ctx.Stderr, "Could not clear the marker: %v", err)
		return 1
	}
	_, _ = config.TakeNote(ctx.ConfigDir, sessionID)
	ui.OK(ctx.Stdout, "Cleared. This session is no longer read-only.")
	return 0
}
