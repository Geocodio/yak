// Package hook implements the Claude Code plugin hooks. A hook prints nothing and makes no network call
// unless a teleport marker exists for the session, and it never fails a prompt.
package hook

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"regexp"
	"strconv"
	"strings"
	"time"

	"github.com/geocodio/yak/cli/internal/api"
	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/link"
	"github.com/geocodio/yak/cli/internal/session"
	"github.com/geocodio/yak/cli/internal/slash"
)

// MaxInputBytes caps what a hook reads from stdin.
const MaxInputBytes = 1 << 20

const networkTimeout = 3 * time.Second

// Input is the JSON Claude Code sends a hook on stdin.
type Input struct {
	SessionID     string `json:"session_id"`
	Cwd           string `json:"cwd"`
	HookEventName string `json:"hook_event_name"`
	Source        string `json:"source"`
	Prompt        string `json:"prompt"`
}

// HookDeps are the hook's dependencies. API is called only once a marker needs the network.
type HookDeps struct {
	ConfigDir string
	API       func() (*api.Client, bool)
	Out       io.Writer
	// Binary is the absolute path of the running yak; empty falls back to "yak".
	Binary string
	// Home locates the record of the registered yak:// handler.
	Home string
	// RefreshHandler re-registers the yak:// handler for Binary; nil disables the refresh.
	RefreshHandler func()
	// RunTeleport runs yak teleport with args and returns what it printed; nil leaves /yak teleport to the command file.
	RunTeleport func(args []string) (output string, exitCode int)
}

// ReadInput decodes stdin; ok is false for anything unreadable.
func ReadInput(r io.Reader) (Input, bool) {
	var in Input
	b, err := io.ReadAll(io.LimitReader(r, MaxInputBytes+1))
	if err != nil || len(b) > MaxInputBytes || json.Unmarshal(b, &in) != nil {
		return Input{}, false
	}
	return in, true
}

func context3s() (context.Context, context.CancelFunc) {
	return context.WithTimeout(context.Background(), networkTimeout)
}

func output(event, text string) string {
	b, err := json.Marshal(map[string]any{"hookSpecificOutput": map[string]string{
		"hookEventName": event, "additionalContext": text,
	}})
	if err != nil {
		return ""
	}
	return string(b) + "\n"
}

// SessionStart prints the environment note once on resume, plus unseen stack messages for a device-owned thread.
func SessionStart(d HookDeps, in Input) string {
	refreshHandler(d)
	if in.Source != "resume" || !session.ValidSessionID(in.SessionID) {
		return ""
	}
	var parts []string
	if note, ok := config.TakeNote(d.ConfigDir, in.SessionID); ok {
		parts = append(parts, strings.TrimSpace(note))
	}
	if m, ok := config.ReadMarker(d.ConfigDir, in.SessionID); ok && m.Owner == "device" {
		if text := stackMessages(d, in.SessionID, m); text != "" {
			parts = append(parts, text)
		}
	}
	if len(parts) == 0 {
		return ""
	}
	return output("SessionStart", strings.Join(parts, "\n\n"))
}

// refreshHandler re-registers the yak:// handler when yak moved since it was installed. A person who never installed
// the handler has no record, so nothing is registered on their behalf.
func refreshHandler(d HookDeps) {
	if d.RefreshHandler == nil || d.Binary == "" || d.Home == "" {
		return
	}
	if registered := link.RegisteredBinary(d.Home); registered != "" && registered != d.Binary {
		d.RefreshHandler()
	}
}

// UserPromptSubmit blocks prompts in a Yak-owned session and hands over stack messages in a device-owned one.
func UserPromptSubmit(d HookDeps, in Input) string {
	if !session.ValidSessionID(in.SessionID) {
		return ""
	}
	m, ok := config.ReadMarker(d.ConfigDir, in.SessionID)
	if ok && m.Owner == "yak" {
		bin := "yak"
		if d.Binary != "" {
			bin = link.ShellQuote(d.Binary)
		}
		return block(fmt.Sprintf("This session is on Yak. Run %s teleport %d to bring it back, or run %s marker clear %s to use it as a local session again (its work can be teleported up as a new thread).",
			bin, m.ThreadID, bin, in.SessionID))
	}
	if out, handled := slashTeleport(d, in.Prompt); handled {
		return out
	}
	if ok && m.Owner == "device" {
		if text := stackMessages(d, in.SessionID, m); text != "" {
			return output("UserPromptSubmit", text)
		}
	}
	return ""
}

func block(reason string) string {
	b, err := json.Marshal(map[string]string{"decision": "block", "reason": reason})
	if err != nil {
		return ""
	}
	return string(b) + "\n"
}

var slashTeleportPrompt = regexp.MustCompile(`^/yak(:yak)? teleport\b`)

// slashTeleport runs /yak teleport inside the hook. Blocking the prompt shows the output to the person without a
// model turn; no shell is involved, the prompt text only becomes an argument list.
func slashTeleport(d HookDeps, prompt string) (string, bool) {
	if d.RunTeleport == nil || !slashTeleportPrompt.MatchString(prompt) {
		return "", false
	}
	rest := strings.TrimPrefix(strings.TrimPrefix(prompt, "/yak:yak"), "/yak")
	args, err := slash.TeleportArgs(rest)
	if err != nil {
		return block(fmt.Sprintf("Could not read that command: %v. Usage: /yak teleport [<thread>] [\"message\"] [options]", err)), true
	}
	text, _ := d.RunTeleport(args)
	if text = strings.TrimSpace(text); text == "" {
		text = "yak teleport printed nothing."
	}
	return block(text), true
}

func seenPath(dir, sessionID string) string { return filepath.Join(dir, "seen", sessionID) }

func readSeen(dir, sessionID string) int {
	b, err := os.ReadFile(seenPath(dir, sessionID))
	if err != nil {
		return 0
	}
	n, _ := strconv.Atoi(strings.TrimSpace(string(b)))
	return n
}

func writeSeen(dir, sessionID string, id int) {
	path := seenPath(dir, sessionID)
	if os.MkdirAll(filepath.Dir(path), 0o700) == nil {
		_ = os.WriteFile(path, []byte(strconv.Itoa(id)), 0o600)
	}
}

// stackMessages fetches, formats and acknowledges unseen messages. Every failure returns "".
func stackMessages(d HookDeps, sessionID string, m config.Marker) string {
	if d.API == nil {
		return ""
	}
	client, ok := d.API()
	if !ok || client == nil {
		return ""
	}
	ctx, cancel := context3s()
	defer cancel()
	var res struct {
		Messages []api.StackMessage `json:"messages"`
	}
	path := fmt.Sprintf("/threads/%d/stack-messages", m.ThreadID)
	if client.Do(ctx, "GET", path, nil, &res) != nil {
		return ""
	}
	seen := readSeen(d.ConfigDir, sessionID)
	var lines []string
	high := seen
	for _, msg := range res.Messages {
		if msg.ID <= seen {
			continue
		}
		title := msg.FromThread.Title
		if title == "" {
			title = fmt.Sprintf("thread %d", msg.FromThread.ID)
		}
		lines = append(lines, fmt.Sprintf("Message from Yak, %s (%s):\n%s", title, msg.Kind, msg.Text))
		high = max(high, msg.ID)
	}
	if len(res.Messages) > 0 {
		last := res.Messages[len(res.Messages)-1].ID
		// Acknowledge even when everything was already printed, so Yak stops offering it.
		_ = client.Do(ctx, "POST", path+"/ack", map[string]int{"through_id": max(last, high)}, nil)
	}
	if len(lines) == 0 {
		return ""
	}
	writeSeen(d.ConfigDir, sessionID, high)
	return strings.Join(lines, "\n\n")
}
