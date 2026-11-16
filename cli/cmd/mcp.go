package cmd

import (
	"github.com/geocodio/yak/cli/internal/mcpserver"
)

func init() { commands["mcp"] = runMCP }

func runMCP(ctx *Context, _ []string) int {
	if err := mcpserver.Serve(ctx.Stdin, ctx.Stdout); err != nil {
		return 1
	}
	return 0
}
