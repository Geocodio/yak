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
	"strconv"
	"strings"
	"time"

	"github.com/geocodio/yak/cli/internal/api"
	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/link"
	"github.com/geocodio/yak/cli/internal/session"
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

// UserPromptSubmit blocks prompts in a Yak-owned session and hands over stack messages in a device-owned one.
func UserPromptSubmit(d HookDeps, in Input) string {
	if !session.ValidSessionID(in.SessionID) {
		return ""
	}
	m, ok := config.ReadMarker(d.ConfigDir, in.SessionID)
	if !ok {
		return ""
	}
	switch m.Owner {
	case "yak":
		bin := "yak"
		if d.Binary != "" {
			bin = link.ShellQuote(d.Binary)
		}
		b, err := json.Marshal(map[string]string{
			"decision": "block",
			"reason": fmt.Sprintf("This session is on Yak. Run %s teleport %d to bring it back, or run %s marker clear %s to use it as a local session again (its work can be teleported up as a new thread).",
				bin, m.ThreadID, bin, in.SessionID),
		})
		if err != nil {
			return ""
		}
		return string(b) + "\n"
	case "device":
		if text := stackMessages(d, in.SessionID, m); text != "" {
			return output("UserPromptSubmit", text)
		}
	}
	return ""
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
