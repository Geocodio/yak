package teleport

import (
	"context"
	"fmt"
	"strings"
	"time"
	"unicode/utf8"

	"github.com/geocodio/yak/cli/internal/api"
)

const (
	redrawInterval = 150 * time.Millisecond
	// slowAfter is how long one step may run before the live line says it is slower than usual.
	slowAfter = 90 * time.Second
	// stallAfter is how long a teleport may report nothing new before the command gives up on it.
	stallAfter = 11 * time.Minute
	slowHint   = " · slow. Ctrl-C stops; Yak keeps the thread"
	// lineWidth keeps the live line on one row of a standard terminal, so the in-place redraw never wraps.
	lineWidth   = 79
	readyLabel  = "Yak is getting the thread ready"
	ctrlCNote   = " (ctrl-c cancels)"
	waitingTurn = "Waiting for the running turn to finish" + ctrlCNote
)

var spinnerFrames = []string{"⠋", "⠙", "⠹", "⠸", "⠼", "⠴", "⠦", "⠧", "⠇", "⠏"}

// runningLabels name the step Yak is working on now. A step a newer server adds falls back to its humanized name.
var runningLabels = map[string]string{
	"wait_turn": "Waiting for the running turn to finish", "stop_turn": "Stopping the running turn",
	"wake_sandbox": "Waking the sandbox", "commit_wip": "Committing Yak's work", "push": "Pushing the branch",
	"sync_session": "Syncing the session", "fetch_branch": "Fetching the branch", "restore": "Restoring the sandbox",
	"setup": "Running setup", "refresh": "Refreshing dependencies", "session_restore": "Restoring the session",
}

var progressLines = map[string]string{
	"wait_turn": "Waited for the running turn to finish", "stop_turn": "Stopped the running turn",
	"wake_sandbox": "Woke the sandbox", "commit_wip": "Yak committed its work", "push": "Yak pushed the branch",
	"sync_session": "Yak synced the session", "fetch_branch": "Yak fetched the branch", "restore": "Yak restored the sandbox",
	"setup": "Yak ran setup", "refresh": "Yak refreshed dependencies", "session_restore": "Yak restored the session",
}

// sentenceCase lowercases a label's first word for use mid-sentence, keeping the name Yak capitalized.
func sentenceCase(label string) string {
	if label == "" || strings.HasPrefix(label, "Yak ") {
		return label
	}
	return strings.ToLower(label[:1]) + label[1:]
}

func runningLabel(step string) string {
	if label, ok := runningLabels[step]; ok {
		return label
	}
	text := strings.ReplaceAll(step, "_", " ")
	if text == "" {
		return readyLabel
	}
	return strings.ToUpper(text[:1]) + text[1:]
}

// status reports what a teleport is doing. On a terminal it keeps one live line (spinner, step, elapsed time)
// and turns finished steps into permanent lines; elsewhere it prints plain lines and no control characters.
type status struct {
	d          Deps
	key        string
	label      string
	detail     string
	hint       string
	startedAt  time.Time
	remindedAt time.Time
	changedAt  time.Time
	signature  string
	frame      int
	drawn      bool
	printed    map[string]bool
	doneShown  map[string]bool
}

func newStatus(d Deps) *status {
	now := d.Now()
	return &status{d: d, startedAt: now, remindedAt: now, changedAt: now, printed: map[string]bool{}, doneShown: map[string]bool{}}
}

// working runs fn, showing label on the live line while it runs. Off a terminal it just runs fn.
func (d Deps) working(label string, fn func() error) error {
	if !d.Live {
		return fn()
	}
	s := newStatus(d)
	s.set("", label, "")
	errs := make(chan error, 1)
	go func() { errs <- fn() }()
	ticker := time.NewTicker(redrawInterval)
	defer ticker.Stop()
	for {
		s.draw()
		select {
		case err := <-errs:
			s.clear()
			return err
		case <-ticker.C:
		}
	}
}

func (s *status) set(key, label, detail string) {
	if key != s.key || s.label == "" {
		s.startedAt, s.remindedAt = s.d.Now(), s.d.Now()
	}
	s.key, s.label, s.detail = key, label, detail
	if full := s.full(); !s.d.Live && !s.printed[full] {
		s.printed[full] = true
		s.d.say("%s...", full)
	}
}

func (s *status) full() string {
	if s.detail == "" {
		return s.label
	}
	return s.label + ": " + s.detail
}

// observe takes in one poll: it prints the steps that finished, shows the step that runs now, and notes
// whether anything changed since the last poll.
func (s *status) observe(tp api.Teleport) {
	signature := fmt.Sprintf("%s|%s|%v", tp.State, tp.UpdatedAt, tp.Progress)
	if signature != s.signature {
		s.signature, s.changedAt = signature, s.d.Now()
	}
	key, label, detail := "", "", ""
	for _, p := range tp.Progress {
		if line, ok := progressLines[p.Step]; ok && p.Status == "done" && !s.doneShown[p.Step] {
			s.doneShown[p.Step] = true
			s.finished(line)
		}
		if p.Status == "running" {
			key, label, detail = p.Step, runningLabel(p.Step), p.Detail
		}
	}
	switch {
	case tp.State == "waiting_for_turn":
		key, label, detail = "waiting_for_turn", waitingTurn, ""
	case key == "" && tp.State == "preparing" && s.key == "":
		key, label = "preparing", readyLabel
	case key == "":
		return
	}
	s.hint = slowHint
	s.set(key, label, detail)
}

// finished prints a permanent line for something that completed.
func (s *status) finished(line string) {
	s.clear()
	if s.d.Live {
		line = "✓ " + line
	}
	s.d.say("%s", line)
}

func (s *status) clear() {
	if s.drawn {
		fmt.Fprint(s.d.Out, "\r\x1b[K")
		s.drawn = false
	}
}

func (s *status) draw() {
	if s.label == "" {
		return
	}
	elapsed := s.d.Now().Sub(s.startedAt)
	clock := fmt.Sprintf(" %d:%02d", int(elapsed.Minutes()), int(elapsed.Seconds())%60)
	line := spinnerFrames[s.frame%len(spinnerFrames)] + " " + s.full() + clock
	if elapsed > slowAfter {
		// The step and its detail matter more than the hint, so the hint shrinks first.
		for _, hint := range []string{s.hint, " · slow", ""} {
			if utf8.RuneCountInString(line+hint) <= lineWidth {
				line += hint
				break
			}
		}
	}
	if utf8.RuneCountInString(line) > lineWidth {
		line = truncate(line, lineWidth-utf8.RuneCountInString(clock)) + clock
	}
	s.frame++
	fmt.Fprint(s.d.Out, "\r\x1b[K"+line)
	s.drawn = true
}

// wait sleeps between polls. On a terminal it redraws the live line while it waits; elsewhere it says
// "Still ..." once a minute while the same step runs.
func (s *status) wait(ctx context.Context, pause time.Duration) error {
	if !s.d.Live {
		if now := s.d.Now(); s.label != "" && now.Sub(s.remindedAt) >= time.Minute {
			s.remindedAt = now
			s.d.say("Still %s (%dm)", sentenceCase(strings.TrimSuffix(s.label, ctrlCNote)), int(now.Sub(s.startedAt).Minutes()))
		}
		return s.d.sleep(ctx, pause)
	}
	for end := s.d.Now().Add(pause); ; {
		s.draw()
		left := end.Sub(s.d.Now())
		if left <= 0 {
			return ctx.Err()
		}
		if err := s.d.sleep(ctx, min(left, redrawInterval)); err != nil {
			return err
		}
	}
}

// stalled returns the error to stop with when nothing has changed for too long. A teleport that waits for a
// running turn has nothing to report, so it never stalls.
func (s *status) stalled(state, advice string) *UserError {
	idle := s.d.Now().Sub(s.changedAt)
	if idle < stallAfter || state == "waiting_for_turn" {
		return nil
	}
	last := sentenceCase(strings.TrimSuffix(firstNonEmpty(s.label, readyLabel), ctrlCNote))
	return userErrorf(nil, "Yak stopped reporting progress %d minutes ago (last step: %s). %s", int(idle.Minutes()), last, advice)
}
