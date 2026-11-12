// Package slash maps the text after /yak in Claude Code to command arguments.
package slash

import (
	"errors"
	"fmt"
	"strings"

	"github.com/geocodio/yak/cli/internal/link"
)

// TeleportArgs returns the argv for the teleport command given the text after
// /yak. Text that does not start with a flag or a thread id is the message.
func TeleportArgs(raw string) ([]string, error) {
	raw = strings.TrimSpace(raw)
	rest := ""
	if raw != "" {
		word, tail, _ := strings.Cut(raw, " ")
		if word != "teleport" {
			return nil, fmt.Errorf("unknown subcommand %q", word)
		}
		rest = strings.TrimSpace(tail)
	}
	if rest == "" {
		return []string{}, nil
	}
	first, _, _ := strings.Cut(rest, " ")
	if strings.HasPrefix(first, "-") || link.ValidThreadID(first) {
		return tokenize(rest)
	}
	if q := rest[0]; q == '"' || q == '\'' {
		if len(rest) < 2 || rest[len(rest)-1] != q {
			return nil, errors.New("unbalanced quote")
		}
		rest = rest[1 : len(rest)-1]
	}
	return []string{"--message", rest}, nil
}

// tokenize splits on spaces, honouring single and double quotes.
func tokenize(s string) ([]string, error) {
	tokens := []string{}
	var cur strings.Builder
	var quote rune
	inToken := false
	for _, r := range s {
		switch {
		case quote != 0:
			if r == quote {
				quote = 0
			} else {
				cur.WriteRune(r)
			}
		case r == '"' || r == '\'':
			quote, inToken = r, true
		case r == ' ' || r == '\t':
			if inToken {
				tokens = append(tokens, cur.String())
				cur.Reset()
				inToken = false
			}
		default:
			cur.WriteRune(r)
			inToken = true
		}
	}
	if quote != 0 {
		return nil, errors.New("unbalanced quote")
	}
	if inToken {
		tokens = append(tokens, cur.String())
	}
	return tokens, nil
}
