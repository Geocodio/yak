package fakeyak

import (
	"encoding/json"
	"fmt"
	"net/http"
	"os"
	"os/exec"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/geocodio/yak/cli/internal/api"
	"github.com/geocodio/yak/cli/internal/gitx"
	"github.com/geocodio/yak/cli/internal/gitx/testrepo"
)

const teleportIdleLimit = 30 * time.Minute

type teleportRow struct {
	id         string
	threadID   int
	deviceID   int
	direction  string
	state      string
	created    time.Time
	updated    time.Time
	failure    *api.Failure
	reason     string
	detail     string
	sessionID  string
	visibility string
	upRepos    []api.BeginRepo
	completed  any
	pollsLeft  int
	completeBy time.Time
}

type downScript struct {
	manifest api.DownManifest
	states   []string
}

type teleportState struct {
	rows       map[string]*teleportRow
	nextRow    int
	nextThread int
	scripts    map[int]*downScript
	last       string

	failComplete *api.Failure
	slowComplete int
	sandbox      string

	messages map[int][]api.StackMessage
	acked    map[int]int
}

func (s *Server) now() time.Time {
	if s.Clock != nil {
		return s.Clock()
	}
	return time.Now()
}

// AddRepoWithOrigin creates a bare git repository that stands in for GitHub and registers it as a Yak repo. Its
// path is the clone URL, so a checkout whose origin is that path resolves to the repo.
func (s *Server) AddRepoWithOrigin(t testing.TB, slug string) string {
	t.Helper()
	dir := t.TempDir()
	cmd := exec.Command("git", "init", "-q", "--bare", "-b", "main", dir)
	cmd.Env = append(os.Environ(), testrepo.Env...)
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("git init --bare: %v: %s", err, out)
	}
	s.mu.Lock()
	defer s.mu.Unlock()
	s.Repos[NormalizeRemote(dir)] = Repo{Slug: slug, DefaultBranch: "main", CloneURL: dir, BarePath: dir}
	return dir
}

// ScriptDownStates scripts what GET /teleports/{id} answers for the next down teleport of the thread: each poll
// moves one state along (waiting_for_turn, preparing, ready, failed) and the last state repeats. The manifest is
// served once the state is ready.
func (s *Server) ScriptDownStates(threadID int, manifest api.DownManifest, states ...string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.tele.scripts == nil {
		s.tele.scripts = map[int]*downScript{}
	}
	s.tele.scripts[threadID] = &downScript{manifest: manifest, states: states}
}

// FailNextComplete makes the next teleport-up complete fail with 409 teleport_failed.
func (s *Server) FailNextComplete(code, message string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.tele.failComplete = &api.Failure{Code: code, Message: message}
}

// SlowCompleteForPolls makes the next teleport-up complete answer 202 and finish after n polls.
func (s *Server) SlowCompleteForPolls(n int) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.tele.slowComplete = n
}

// SetSandboxState sets sandbox.state in the next up completes (ready, restoring, waiting_for_slot).
func (s *Server) SetSandboxState(state string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.tele.sandbox = state
}

// LastTeleportID is the id of the most recently created teleport.
func (s *Server) LastTeleportID() string {
	s.mu.Lock()
	defer s.mu.Unlock()
	return s.tele.last
}

// Teleport returns the teleport object for an id.
func (s *Server) Teleport(id string) (api.Teleport, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()
	row, ok := s.tele.rows[id]
	if !ok {
		return api.Teleport{}, false
	}
	s.expire(row)
	return row.object(), true
}

// CancelInfo returns the reason and detail the teleport was cancelled with.
func (s *Server) CancelInfo(id string) (reason, detail string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if row, ok := s.tele.rows[id]; ok {
		return row.reason, row.detail
	}
	return "", ""
}

// AddStackMessage queues a stack message for a thread.
func (s *Server) AddStackMessage(threadID int, m api.StackMessage) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if s.tele.messages == nil {
		s.tele.messages = map[int][]api.StackMessage{}
	}
	s.tele.messages[threadID] = append(s.tele.messages[threadID], m)
}

func terminalState(state string) bool {
	switch state {
	case "completed", "failed", "cancelled", "expired":
		return true
	}
	return false
}

func (row *teleportRow) object() api.Teleport {
	return api.Teleport{
		TeleportID: row.id, ThreadID: row.threadID, Direction: row.direction, State: row.state, DeviceID: row.deviceID,
		CreatedAt: row.created.UTC().Format(time.RFC3339), UpdatedAt: row.updated.UTC().Format(time.RFC3339),
		ExpiresAt: row.updated.Add(teleportIdleLimit).UTC().Format(time.RFC3339), PollAfterSeconds: 2,
		Progress: []api.Progress{}, Failure: row.failure,
	}
}

// expire moves a teleport that saw no call for 30 minutes to expired and frees the thread.
func (s *Server) expire(row *teleportRow) {
	if !terminalState(row.state) && s.now().Sub(row.updated) > teleportIdleLimit {
		s.finish(row, "expired")
	}
}

func (s *Server) finish(row *teleportRow, state string) {
	row.state = state
	row.updated = s.now()
	if tp, ok := s.sessions.teleports[row.threadID]; ok && tp.deviceID == row.deviceID {
		delete(s.sessions.teleports, row.threadID)
	}
}

func (s *Server) openRow(threadID int) *teleportRow {
	for _, row := range s.tele.rows {
		if row.threadID == threadID {
			s.expire(row)
			if !terminalState(row.state) {
				return row
			}
		}
	}
	return nil
}

func (s *Server) newRow(threadID, deviceID int, direction, state string) *teleportRow {
	if s.tele.rows == nil {
		s.tele.rows = map[string]*teleportRow{}
	}
	s.tele.nextRow++
	now := s.now()
	row := &teleportRow{
		id: fmt.Sprintf("01J8Z%021d", s.tele.nextRow), threadID: threadID, deviceID: deviceID,
		direction: direction, state: state, created: now, updated: now,
	}
	s.tele.rows[row.id] = row
	s.tele.last = row.id
	s.OpenTeleportLocked(threadID, deviceID, direction, state)
	return row
}

// OpenTeleportLocked registers the teleport for session authorization; the caller holds the mutex.
func (s *Server) OpenTeleportLocked(threadID, deviceID int, direction, state string) {
	if s.sessions.teleports == nil {
		s.sessions.teleports = map[int]openTeleport{}
	}
	s.sessions.teleports[threadID] = openTeleport{deviceID, direction, state}
}

func (s *Server) setState(row *teleportRow, state string) {
	row.state = state
	row.updated = s.now()
	if tp, ok := s.sessions.teleports[row.threadID]; ok && tp.deviceID == row.deviceID {
		tp.state = state
		s.sessions.teleports[row.threadID] = tp
	}
}

func (s *Server) teleportRoute(w http.ResponseWriter, r *http.Request, device *Device, p string) {
	rest := strings.Trim(strings.TrimPrefix(p, "/teleports"), "/")
	if rest == "" {
		if r.Method == "POST" {
			s.begin(w, r, device)
			return
		}
		writeErr(w, 404, "not_found", "No such route in the fake server.", nil)
		return
	}
	id, call, _ := strings.Cut(rest, "/")
	row, ok := s.tele.rows[id]
	if !ok || row.deviceID != device.ID {
		writeErr(w, 404, "teleport_not_found", "That teleport was not found.", nil)
		return
	}
	switch {
	case r.Method == "GET" && call == "":
		s.getTeleport(w, row)
	case r.Method == "POST" && call == "complete":
		s.complete(w, r, row, device)
	case r.Method == "POST" && call == "cancel":
		s.cancel(w, r, row)
	default:
		writeErr(w, 404, "not_found", "No such route in the fake server.", nil)
	}
}

func slugify(text string) string {
	var b strings.Builder
	dash := false
	for _, c := range strings.ToLower(text) {
		if c >= 'a' && c <= 'z' || c >= '0' && c <= '9' {
			b.WriteRune(c)
			dash = false
		} else if !dash && b.Len() > 0 {
			b.WriteByte('-')
			dash = true
		}
	}
	out := strings.Trim(b.String(), "-")
	if len(out) > 40 {
		out = strings.Trim(out[:40], "-")
	}
	if out == "" {
		out = "thread"
	}
	return out
}

func (s *Server) repoBySlug(slug string) (Repo, bool) {
	for _, repo := range s.Repos {
		if repo.Slug == slug {
			return repo, true
		}
	}
	return Repo{}, false
}

func (s *Server) begin(w http.ResponseWriter, r *http.Request, device *Device) {
	var probe struct {
		Direction string `json:"direction"`
	}
	var body json.RawMessage
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		writeErr(w, 422, "validation_failed", "Invalid body.", nil)
		return
	}
	_ = json.Unmarshal(body, &probe)
	switch probe.Direction {
	case "up":
		var req api.BeginUpRequest
		if json.Unmarshal(body, &req) != nil {
			writeErr(w, 422, "validation_failed", "Invalid body.", nil)
			return
		}
		s.beginUp(w, device, req)
	case "down":
		var req api.BeginDownRequest
		if json.Unmarshal(body, &req) != nil {
			writeErr(w, 422, "validation_failed", "Invalid body.", nil)
			return
		}
		s.beginDown(w, device, req)
	default:
		writeErr(w, 422, "validation_failed", "direction must be up or down.", map[string]any{"errors": map[string][]string{"direction": {"Must be up or down."}}})
	}
}

func (s *Server) deviceName(id int) string {
	if d, ok := s.Devices[id]; ok {
		return d.Name
	}
	return ""
}

func (s *Server) inProgress(w http.ResponseWriter, row *teleportRow) {
	writeErr(w, 409, "teleport_in_progress", "Another teleport is already running for this thread.", map[string]any{
		"teleport_id": row.id, "direction": row.direction, "device_name": s.deviceName(row.deviceID),
	})
}

func (s *Server) beginUp(w http.ResponseWriter, device *Device, req api.BeginUpRequest) {
	if len(req.Repos) < 1 || len(req.Repos) > 10 || req.Repos[0].Role != "primary" || req.SessionID == "" {
		writeErr(w, 422, "validation_failed", "Send a session id and between 1 and 10 repos, the first one primary.", nil)
		return
	}
	for _, rr := range req.Repos {
		if _, ok := s.repoBySlug(rr.Repo); !ok {
			writeErr(w, 422, "repo_not_on_yak", "That repo is not on Yak.", map[string]any{"repo": rr.Repo})
			return
		}
	}
	var thread *Thread
	for _, t := range s.Threads {
		if t.ActiveSessionID == req.SessionID && (thread == nil || thread.Status == "closed" || t.ID > thread.ID) {
			thread = t
		}
	}
	if thread != nil && thread.Status != "closed" {
		if row := s.openRow(thread.ID); row != nil {
			if row.deviceID == device.ID && row.direction == "up" {
				s.upResponse(w, row, thread, req, false, true)
				return
			}
			s.inProgress(w, row)
			return
		}
		switch {
		case thread.OwnerSide == "yak":
			writeErr(w, 409, "thread_owned_by_yak", "The thread is on Yak. Run yak teleport <thread> to bring it down first.", map[string]any{"thread_id": thread.ID})
			return
		case thread.OwnerDevice != nil && thread.OwnerDevice.ID != device.ID:
			writeErr(w, 409, "owned_by_other_device", "The thread is on another computer.", map[string]any{"device_name": thread.OwnerDevice.Name})
			return
		}
		primary := req.Repos[0]
		if thread.Branch != "" && (primary.Branch == nil || *primary.Branch != thread.Branch) {
			current := ""
			if primary.Branch != nil {
				current = *primary.Branch
			}
			writeErr(w, 409, "branch_mismatch", "This checkout is on another branch than the thread.", map[string]any{"thread_branch": thread.Branch, "current_branch": current})
			return
		}
		s.upResponse(w, s.newUpRow(thread, device, req), thread, req, false, false)
		return
	}
	created := &Thread{
		ID: s.newThreadID(), Title: req.TitleHint, Repo: req.Repos[0].Repo, Status: "open", Visibility: "private",
		CreatedByUserID: s.User.ID, OwnerSide: "device", OwnerDevice: &api.OwnerDevice{ID: device.ID, Name: device.Name, IsThisDevice: true},
		ClaudeAccount: api.ClaudeAccount{Name: "Yak Team"}, ActiveSessionID: req.SessionID,
	}
	created.URL = fmt.Sprintf("%s/threads/%d", s.srv.URL, created.ID)
	if thread != nil {
		created.ContinuedFromThreadID = &thread.ID
	}
	if req.Repos[0].OnDefaultBranch || req.Repos[0].Branch == nil {
		created.Branch = "yak/" + slugify(req.TitleHint)
	} else {
		created.Branch = *req.Repos[0].Branch
	}
	s.Threads[created.ID] = created
	s.upResponse(w, s.newUpRow(created, device, req), created, req, true, false)
}

func (s *Server) newThreadID() int {
	if s.tele.nextThread == 0 {
		s.tele.nextThread = 1000
	}
	s.tele.nextThread++
	return s.tele.nextThread
}

func (s *Server) newUpRow(thread *Thread, device *Device, req api.BeginUpRequest) *teleportRow {
	row := s.newRow(thread.ID, device.ID, "up", "open")
	row.sessionID, row.upRepos = req.SessionID, req.Repos
	return row
}

func (s *Server) upResponse(w http.ResponseWriter, row *teleportRow, thread *Thread, req api.BeginUpRequest, isNew, resumed bool) {
	if resumed {
		row.updated = s.now()
	}
	var firstAt *string
	if thread.LastTeleportUp != nil && !isNew {
		first := thread.LastTeleportUp.At
		firstAt = &first
	}
	repos := make([]api.BeginUpRepo, 0, len(req.Repos))
	for _, rr := range req.Repos {
		repo, _ := s.repoBySlug(rr.Repo)
		out := api.BeginUpRepo{Repo: rr.Repo, Role: rr.Role, CloneURL: repo.CloneURL, SandboxPath: "/workspace"}
		if rr.Role != "primary" {
			out.SandboxPath = "/extras/" + rr.Repo[strings.LastIndex(rr.Repo, "/")+1:]
		}
		switch {
		case rr.Role == "context":
		case rr.Role == "primary" || rr.OnDefaultBranch:
			out.Branch = thread.Branch
			if rr.Role != "primary" || rr.Branch == nil || *rr.Branch != thread.Branch {
				out.CreateBranch = rr.OnDefaultBranch
			}
			if rr.Role != "primary" {
				out.Branch = "yak/" + slugify(thread.Title)
			}
		default:
			if rr.Branch != nil {
				out.Branch = *rr.Branch
			}
		}
		repos = append(repos, out)
	}
	resp := api.BeginUpResponse{
		TeleportID: row.id, Resumed: resumed,
		Thread: api.BeginUpThread{
			ID: thread.ID, IsNew: isNew, ContinuedFromThreadID: thread.ContinuedFromThreadID, Slug: slugify(thread.Title),
			Visibility: thread.Visibility, FirstTeleportedAt: firstAt, ClaudeAccount: thread.ClaudeAccount, URL: thread.URL,
		},
		Repos: repos, ExpiresAt: row.updated.Add(teleportIdleLimit).UTC().Format(time.RFC3339),
	}
	writeJSON(w, 200, resp)
}

func (s *Server) beginDown(w http.ResponseWriter, device *Device, req api.BeginDownRequest) {
	thread, ok := s.Threads[req.ThreadID]
	if !ok {
		writeErr(w, 404, "thread_not_found", "That thread was not found.", nil)
		return
	}
	if len(req.Repos) > 0 && req.Repos[0].Repo != thread.Repo {
		writeErr(w, 422, "wrong_repo", "This checkout is not the thread's repo.", map[string]any{"thread_repo": thread.Repo, "given_repo": req.Repos[0].Repo})
		return
	}
	if row := s.openRow(thread.ID); row != nil {
		if row.deviceID == device.ID && row.direction == "down" {
			row.updated = s.now()
			writeJSON(w, 200, api.BeginDownResponse{TeleportID: row.id, Resumed: true, Teleport: row.object()})
			return
		}
		s.inProgress(w, row)
		return
	}
	if thread.OwnerSide == "device" && thread.OwnerDevice != nil {
		if thread.OwnerDevice.ID == device.ID {
			writeErr(w, 409, "already_on_this_device", "The thread is already on this computer.", nil)
		} else {
			writeErr(w, 409, "thread_owned_by_device", "The thread is on another computer. Teleport it up from there first.", map[string]any{"device_name": thread.OwnerDevice.Name})
		}
		return
	}
	if thread.TurnRunning && req.RunningTurn == "" {
		writeErr(w, 409, "turn_running", "A turn is running on Yak.", map[string]any{"turn_started_at": s.now().UTC().Format(time.RFC3339)})
		return
	}
	if thread.Visibility == "team" && req.Visibility == "" {
		writeErr(w, 409, "visibility_choice_required", "Choose whether the thread stays visible to the team.", map[string]any{"current": "team"})
		return
	}
	state := "preparing"
	if thread.TurnRunning && req.RunningTurn == "wait" {
		state = "waiting_for_turn"
	}
	row := s.newRow(thread.ID, device.ID, "down", state)
	row.visibility = req.Visibility
	writeJSON(w, 200, api.BeginDownResponse{TeleportID: row.id, Teleport: row.object()})
}

func (s *Server) getTeleport(w http.ResponseWriter, row *teleportRow) {
	s.expire(row)
	if row.direction == "up" && row.state == "preparing" && row.pollsLeft > 0 {
		row.pollsLeft--
		if row.pollsLeft == 0 {
			s.finishUp(row)
		}
	}
	if row.direction == "down" && !terminalState(row.state) {
		if script := s.tele.scripts[row.threadID]; script != nil && len(script.states) > 0 {
			next := script.states[0]
			if len(script.states) > 1 {
				script.states = script.states[1:]
			}
			if next == "failed" {
				row.failure = &api.Failure{Code: "push_failed", Message: "Yak could not push the branch."}
				s.finish(row, "failed")
			} else {
				s.setState(row, next)
			}
		}
	}
	row.updated = s.now()
	if row.direction == "down" && row.state == "ready" {
		manifest := s.tele.scripts[row.threadID].manifest
		manifest.Teleport = row.object()
		writeJSON(w, 200, manifest)
		return
	}
	writeJSON(w, 200, row.object())
}

func (s *Server) gone(w http.ResponseWriter, row *teleportRow) bool {
	switch row.state {
	case "expired":
		writeErr(w, 409, "teleport_expired", "That teleport expired. Start again.", nil)
	case "cancelled":
		writeErr(w, 409, "no_open_teleport", "That teleport was cancelled.", nil)
	case "failed":
		details := map[string]any{}
		if row.failure != nil {
			details["code"], details["message"] = row.failure.Code, row.failure.Message
		}
		writeErr(w, 409, "teleport_failed", "The teleport failed. Nothing moved.", details)
	default:
		return false
	}
	return true
}

func (s *Server) complete(w http.ResponseWriter, r *http.Request, row *teleportRow, device *Device) {
	s.expire(row)
	if row.state == "completed" && row.completed != nil {
		writeJSON(w, 200, row.completed)
		return
	}
	if s.gone(w, row) {
		return
	}
	if row.direction == "up" {
		s.completeUp(w, r, row)
		return
	}
	s.completeDown(w, r, row, device)
}

func (s *Server) completeUp(w http.ResponseWriter, r *http.Request, row *teleportRow) {
	if row.state == "preparing" {
		writeJSON(w, 202, map[string]any{"teleport": row.object()})
		return
	}
	var req api.CompleteUpRequest
	if json.NewDecoder(r.Body).Decode(&req) != nil || len(req.Message) > 20000 {
		writeErr(w, 422, "validation_failed", "Invalid body.", nil)
		return
	}
	for _, rr := range req.Repos {
		repo, ok := s.repoBySlug(rr.Repo)
		if !ok || repo.BarePath == "" {
			continue
		}
		head, err := gitx.Repo{Dir: repo.BarePath, Env: testrepo.Env}.Run("rev-parse", "--verify", "-q", "refs/heads/"+rr.Branch)
		if err != nil || head != rr.HeadSHA {
			writeErr(w, 409, "push_not_visible", "Yak cannot see the pushed branch on GitHub.", map[string]any{"repo": rr.Repo, "branch": rr.Branch})
			return
		}
	}
	key := fileKey{row.threadID, row.sessionID, row.sessionID + ".jsonl"}
	stored, ok := s.sessions.files[key]
	if !ok || s.sessions.checkpoints[key] != hexSum(stored) {
		writeErr(w, 409, "session_incomplete", "The session was not stored completely.", nil)
		return
	}
	if f := s.tele.failComplete; f != nil {
		s.tele.failComplete = nil
		row.failure = f
		s.finish(row, "failed")
		s.gone(w, row)
		return
	}
	row.completeBy = s.now()
	row.completed = nil
	body, _ := json.Marshal(req)
	row.visibility = string(body) // remembered for finishUp
	if n := s.tele.slowComplete; n > 0 {
		s.tele.slowComplete = 0
		row.pollsLeft = n
		s.setState(row, "preparing")
		writeJSON(w, 202, map[string]any{"teleport": row.object()})
		return
	}
	s.finishUp(row)
	writeJSON(w, 200, row.completed)
}

func (s *Server) finishUp(row *teleportRow) {
	var req api.CompleteUpRequest
	_ = json.Unmarshal([]byte(row.visibility), &req)
	thread := s.Threads[row.threadID]
	thread.OwnerSide, thread.OwnerDevice = "yak", nil
	if len(req.Repos) > 0 {
		thread.LastTeleportUp = &api.LastTeleportUp{
			TeleportID: row.id, At: s.now().UTC().Format(time.RFC3339), Branch: req.Repos[0].Branch,
			HeadSHA: req.Repos[0].HeadSHA, DeviceID: row.deviceID,
		}
		thread.Branch = req.Repos[0].Branch
	}
	s.finish(row, "completed")
	sandbox := s.tele.sandbox
	if sandbox == "" {
		sandbox = "ready"
	}
	row.completed = api.CompleteUpResponse{
		Teleport: row.object(), Ownership: "yak", Sandbox: api.Sandbox{State: sandbox}, TurnStarted: req.Message != "",
		Marker: api.Marker{ThreadID: thread.ID, TeleportID: row.id, Owner: "yak", ThreadURL: thread.URL},
	}
}

func (s *Server) completeDown(w http.ResponseWriter, r *http.Request, row *teleportRow, device *Device) {
	if row.state != "ready" {
		writeErr(w, 409, "no_open_teleport", "The teleport is not ready to complete.", nil)
		return
	}
	var req api.CompleteDownRequest
	if json.NewDecoder(r.Body).Decode(&req) != nil || len(req.Applied) == 0 || req.Applied[0].Status != "applied" {
		writeErr(w, 422, "validation_failed", "The primary repo must be applied.", nil)
		return
	}
	manifest := s.tele.scripts[row.threadID].manifest
	want := manifest.Session.SessionID + ".jsonl"
	found := false
	for _, f := range manifest.Session.Files {
		if f.RelPath != want {
			continue
		}
		for _, d := range req.Downloaded {
			found = found || d.RelPath == want && d.SHA256 == f.SHA256
		}
	}
	if !found {
		writeErr(w, 409, "session_incomplete", "The session transcript was not downloaded.", nil)
		return
	}
	thread := s.Threads[row.threadID]
	thread.OwnerSide = "device"
	thread.OwnerDevice = &api.OwnerDevice{ID: device.ID, Name: device.Name, IsThisDevice: true}
	if row.visibility == "private" {
		thread.Visibility = "private"
	}
	s.finish(row, "completed")
	row.completed = api.CompleteDownResponse{
		Teleport: row.object(), Ownership: "device", OwnerDevice: thread.OwnerDevice, Visibility: thread.Visibility,
		Marker: api.Marker{
			ThreadID: thread.ID, TeleportID: row.id, Owner: "device",
			StackMessagesURL: fmt.Sprintf("%s/api/cli/v1/threads/%d/stack-messages", s.srv.URL, thread.ID),
		},
	}
	writeJSON(w, 200, row.completed)
}

func (s *Server) cancel(w http.ResponseWriter, r *http.Request, row *teleportRow) {
	var req api.CancelRequest
	_ = json.NewDecoder(r.Body).Decode(&req)
	switch req.Reason {
	case "user_cancelled", "clash", "dirty_tree", "diverged", "error":
	default:
		writeErr(w, 422, "validation_failed", "Unknown reason.", map[string]any{"errors": map[string][]string{"reason": {"Unknown reason."}}})
		return
	}
	s.expire(row)
	switch row.state {
	case "completed":
		writeErr(w, 409, "teleport_completed", "The teleport already completed.", nil)
		return
	case "cancelled", "failed", "expired":
		writeJSON(w, 200, row.object())
		return
	}
	row.reason, row.detail = req.Reason, req.Detail
	s.finish(row, "cancelled")
	writeJSON(w, 200, row.object())
}

func (s *Server) threadExtraRoute(w http.ResponseWriter, r *http.Request, device *Device, p string) {
	rest := strings.TrimPrefix(p, "/threads/")
	idText, call, _ := strings.Cut(rest, "/")
	threadID, err := strconv.Atoi(idText)
	thread, ok := s.Threads[threadID]
	if err != nil || !ok {
		writeErr(w, 404, "thread_not_found", "That thread was not found.", nil)
		return
	}
	if call == "ssh" {
		writeErr(w, 501, "not_implemented", "yak ssh is not available yet.", nil)
		return
	}
	if thread.OwnerSide != "device" || thread.OwnerDevice == nil || thread.OwnerDevice.ID != device.ID {
		writeErr(w, 409, "not_owner", "This computer does not own the thread.", nil)
		return
	}
	switch {
	case r.Method == "GET" && call == "stack-messages":
		limit := 50
		if n, err := strconv.Atoi(r.URL.Query().Get("limit")); err == nil && n > 0 && n < limit {
			limit = n
		}
		var pending []api.StackMessage
		for _, m := range s.tele.messages[threadID] {
			if m.ID > s.tele.acked[threadID] {
				pending = append(pending, m)
			}
		}
		hasMore := len(pending) > limit
		if hasMore {
			pending = pending[:limit]
		}
		if pending == nil {
			pending = []api.StackMessage{}
		}
		writeJSON(w, 200, map[string]any{"messages": pending, "has_more": hasMore, "owner_side": "device"})
	case r.Method == "POST" && call == "stack-messages/ack":
		var req struct {
			ThroughID int `json:"through_id"`
		}
		_ = json.NewDecoder(r.Body).Decode(&req)
		if s.tele.acked == nil {
			s.tele.acked = map[int]int{}
		}
		count := 0
		for _, m := range s.tele.messages[threadID] {
			if m.ID > s.tele.acked[threadID] && m.ID <= req.ThroughID {
				count++
			}
		}
		if req.ThroughID > s.tele.acked[threadID] {
			s.tele.acked[threadID] = req.ThroughID
		}
		writeJSON(w, 200, map[string]int{"acked": count})
	default:
		writeErr(w, 404, "not_found", "No such route in the fake server.", nil)
	}
}
