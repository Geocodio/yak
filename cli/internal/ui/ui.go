// Package ui holds the terminal prompter and output helpers.
package ui

import (
	"bufio"
	"fmt"
	"io"
	"os"
	"strconv"
	"strings"
)

// Option is one choice in Prompter.Choose.
type Option struct{ Label, Hint string }

// PickItem is one row in Prompter.Pick.
type PickItem struct {
	Label, Hint string
	Selected    bool
}

// Prompter asks the person questions. Every method has a safe default for Enter and for end of input.
type Prompter interface {
	Confirm(question string, def bool) (bool, error)
	Choose(question string, options []Option, defIndex int) (int, error)
	Pick(question string, items []PickItem) ([]int, error)
	Edit(label, current string) (string, error)
}

// IsTTY reports whether f is an interactive terminal.
func IsTTY(f *os.File) bool {
	fi, err := f.Stat()
	return err == nil && fi.Mode()&os.ModeCharDevice != 0
}

// OK, Fail and Warn print a status line with a leading mark.
func OK(w io.Writer, format string, a ...any)   { fmt.Fprintf(w, "✓ "+format+"\n", a...) }
func Fail(w io.Writer, format string, a ...any) { fmt.Fprintf(w, "✗ "+format+"\n", a...) }
func Warn(w io.Writer, format string, a ...any) { fmt.Fprintf(w, "! "+format+"\n", a...) }

type terminal struct {
	in  *bufio.Reader
	out io.Writer
}

// NewTerminal is a line based prompter: numbered choices, Enter takes the default, x toggles in Pick.
func NewTerminal(in io.Reader, out io.Writer) Prompter {
	return &terminal{in: bufio.NewReader(in), out: out}
}

// line returns the trimmed line and whether input ended.
func (t *terminal) line() (string, bool) {
	s, err := t.in.ReadString('\n')
	return strings.TrimSpace(s), err != nil && s == ""
}

func (t *terminal) Confirm(question string, def bool) (bool, error) {
	hint := "[y/N]"
	if def {
		hint = "[Y/n]"
	}
	for {
		fmt.Fprintf(t.out, "%s %s ", question, hint)
		s, eof := t.line()
		switch strings.ToLower(s) {
		case "":
			return def, nil
		case "y", "yes":
			return true, nil
		case "n", "no":
			return false, nil
		}
		if eof {
			return def, nil
		}
	}
}

func (t *terminal) Choose(question string, options []Option, defIndex int) (int, error) {
	fmt.Fprintln(t.out, question)
	for i, o := range options {
		mark := " "
		if i == defIndex {
			mark = "*"
		}
		line := fmt.Sprintf("  %s %d) %s", mark, i+1, o.Label)
		if o.Hint != "" {
			line += "  " + o.Hint
		}
		fmt.Fprintln(t.out, line)
	}
	for {
		fmt.Fprintf(t.out, "Choose 1-%d [%d]: ", len(options), defIndex+1)
		s, eof := t.line()
		if s == "" {
			return defIndex, nil
		}
		if n, err := strconv.Atoi(s); err == nil && n >= 1 && n <= len(options) {
			return n - 1, nil
		}
		if eof {
			return defIndex, nil
		}
	}
}

func (t *terminal) Pick(question string, items []PickItem) ([]int, error) {
	items = append([]PickItem(nil), items...)
	for {
		fmt.Fprintln(t.out, question)
		for i, it := range items {
			box := "[ ]"
			if it.Selected {
				box = "[x]"
			}
			line := fmt.Sprintf("  %s %d) %s", box, i+1, it.Label)
			if it.Hint != "" {
				line += "  " + it.Hint
			}
			fmt.Fprintln(t.out, line)
		}
		fmt.Fprint(t.out, "Type x and a number to toggle, Enter to continue: ")
		s, eof := t.line()
		if s == "" || eof {
			break
		}
		if n, err := strconv.Atoi(strings.TrimSpace(strings.TrimPrefix(strings.ToLower(s), "x"))); err == nil && strings.HasPrefix(strings.ToLower(s), "x") && n >= 1 && n <= len(items) {
			items[n-1].Selected = !items[n-1].Selected
		}
	}
	return selected(items), nil
}

func (t *terminal) Edit(label, current string) (string, error) {
	fmt.Fprintf(t.out, "%s [%s]: ", label, current)
	s, _ := t.line()
	if s == "" {
		return current, nil
	}
	return s, nil
}

func selected(items []PickItem) []int {
	var out []int
	for i, it := range items {
		if it.Selected {
			out = append(out, i)
		}
	}
	return out
}

type nonInteractive struct{}

// NewNonInteractive answers every question with its default.
func NewNonInteractive() Prompter { return nonInteractive{} }

func (nonInteractive) Confirm(_ string, def bool) (bool, error) { return def, nil }
func (nonInteractive) Choose(_ string, _ []Option, defIndex int) (int, error) {
	return defIndex, nil
}
func (nonInteractive) Pick(_ string, items []PickItem) ([]int, error) { return selected(items), nil }
func (nonInteractive) Edit(_, current string) (string, error)         { return current, nil }
