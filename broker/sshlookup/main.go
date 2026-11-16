// Command sshlookup is the sshd AuthorizedKeysCommand for the jump host.
// It prints nothing and exits 0 on every failure, which means no login.
package main

import (
	"context"
	"fmt"
	"os"
	"strings"
	"time"
)

func main() {
	if len(os.Args) < 2 {
		return
	}

	tokenBytes, err := os.ReadFile(os.Getenv("YAK_SSH_TOKEN_FILE"))
	if err != nil {
		return
	}

	ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
	defer cancel()

	lines, err := Lookup(ctx, os.Getenv("YAK_SSH_URL"), strings.TrimSpace(string(tokenBytes)), os.Args[1])
	if err != nil {
		return
	}

	for _, line := range lines {
		fmt.Println(line)
	}
}
