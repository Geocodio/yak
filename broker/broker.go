package main

import (
	"context"
	"encoding/json"
	"log/slog"
	"os"
	"strconv"
	"strings"
	"sync"
	"time"

	"github.com/redis/go-redis/v9"
)

const (
	channelsKey   = "broker:channels"
	heartbeatKey  = "broker:alive"
	containersKey = "broker:containers"
	groupName     = "broker"
	// outStreamMaxLength caps a channel's out stream even when nobody acknowledges it.
	outStreamMaxLength = 200000
	forgetWait         = 30 * time.Second
)

type command struct {
	Type      string          `json:"type"`
	Container string          `json:"container"`
	UID       int             `json:"uid"`
	Cwd       string          `json:"cwd"`
	Start     json.RawMessage `json:"start"`
	UUID      string          `json:"uuid"`
	Text      string          `json:"text"`
	Priority  string          `json:"priority"`
	Model     string          `json:"model"`
	CallID    string          `json:"call_id"`
	IsError   bool            `json:"is_error"`
	Content   string          `json:"content"`
}

type userMessage struct {
	Type     string `json:"type"`
	UUID     string `json:"uuid"`
	Text     string `json:"text"`
	Priority string `json:"priority,omitempty"`
}

type toolResult struct {
	Type    string `json:"type"`
	CallID  string `json:"call_id"`
	IsError bool   `json:"is_error"`
	Content string `json:"content"`
}

// channelState is the broker's view of one channel. mu orders sequence
// assignment together with the XADD so entries land in sequence order.
type channelState struct {
	name      string
	mu        sync.Mutex
	container string
	// placeholder is the auth proxy placeholder of the current runner, revoked when it ends.
	placeholder string
	generation  int
	sequence    int
	process     Process
}

type Broker struct {
	redis    *redis.Client
	spawner  Spawner
	revoker  PlaceholderRevoker
	logger   *slog.Logger
	consumer string

	mu       sync.Mutex
	channels map[string]*channelState
	wg       sync.WaitGroup
}

// New builds a broker. A nil revoker revokes nothing.
func New(client *redis.Client, spawner Spawner, revoker PlaceholderRevoker, logger *slog.Logger) *Broker {
	if revoker == nil {
		revoker = noopRevoker{}
	}
	hostname, _ := os.Hostname()
	return &Broker{redis: client, spawner: spawner, revoker: revoker, logger: logger, consumer: hostname, channels: map[string]*channelState{}}
}

// Run serves until the context ends, then kills every runner (their pipes
// die with the broker) and returns nil.
func (b *Broker) Run(ctx context.Context) error {
	if err := b.startup(ctx); err != nil {
		return err
	}
	ticker := time.NewTicker(time.Second)
	defer ticker.Stop()
	for {
		b.redis.Set(ctx, heartbeatKey, time.Now().Unix(), 15*time.Second)
		b.refresh(ctx, "0")
		select {
		case <-ctx.Done():
			b.killAll()
			b.wg.Wait()
			return nil
		case <-ticker.C:
		}
	}
}

// startup skips every command written before this broker started, kills the
// runners an earlier broker left behind and announces itself on each channel.
func (b *Broker) startup(ctx context.Context) error {
	names, err := b.redis.SMembers(ctx, channelsKey).Result()
	if err != nil {
		return err
	}
	for _, name := range names {
		state := b.watch(ctx, name, "$")
		if state.container != "" {
			if err := b.spawner.KillAllRunners(ctx, state.container); err != nil {
				b.logger.Warn("startup kill failed", "channel", name, "error", err)
			}
		}
		b.emitBroker(state, "broker.restarted", nil)
	}
	return nil
}

// refresh starts a reader for every channel that is not watched yet. A new
// channel's group starts at 0 so a command written before the group existed
// is still read.
func (b *Broker) refresh(ctx context.Context, groupStart string) {
	names, err := b.redis.SMembers(ctx, channelsKey).Result()
	if err != nil {
		if ctx.Err() == nil {
			b.logger.Warn("read channels failed", "error", err)
		}
		return
	}
	for _, name := range names {
		b.mu.Lock()
		_, known := b.channels[name]
		b.mu.Unlock()
		if !known {
			b.watch(ctx, name, groupStart)
		}
	}
}

func (b *Broker) watch(ctx context.Context, name string, groupStart string) *channelState {
	in := name + ":in"
	err := b.redis.XGroupCreateMkStream(ctx, in, groupName, groupStart).Err()
	if err != nil && !strings.Contains(err.Error(), "BUSYGROUP") {
		b.logger.Warn("create group failed", "channel", name, "error", err)
	}
	if groupStart == "$" {
		b.skipBacklog(ctx, in)
	}

	state := &channelState{name: name}
	state.container, _ = b.redis.HGet(ctx, containersKey, name).Result()
	if last, err := b.redis.XRevRangeN(ctx, name+":out", "+", "-", 1).Result(); err == nil && len(last) == 1 {
		state.generation = toInt(last[0].Values["generation"])
		state.sequence = toInt(last[0].Values["sequence"])
	}
	b.mu.Lock()
	b.channels[name] = state
	b.mu.Unlock()

	b.wg.Add(1)
	go func() {
		defer b.wg.Done()
		b.readCommands(ctx, state)
	}()
	return state
}

// skipBacklog moves the group past every command already in the stream and
// deletes those commands. The group moves to the last existing id, not to
// "$", so a command written during startup is neither skipped nor left behind.
func (b *Broker) skipBacklog(ctx context.Context, in string) {
	last, err := b.redis.XRevRangeN(ctx, in, "+", "-", 1).Result()
	if err != nil || len(last) == 0 {
		if err != nil {
			b.logger.Warn("read backlog failed", "stream", in, "error", err)
		}
		return
	}
	if err := b.redis.XGroupSetID(ctx, in, groupName, last[0].ID).Err(); err != nil {
		b.logger.Warn("move group past backlog failed", "stream", in, "error", err)
	}
	entries, err := b.redis.XRange(ctx, in, "-", last[0].ID).Result()
	if err != nil {
		b.logger.Warn("list backlog failed", "stream", in, "error", err)
		return
	}
	for _, entry := range entries {
		if err := b.redis.XDel(ctx, in, entry.ID).Err(); err != nil {
			b.logger.Warn("delete skipped entry failed", "stream", in, "id", entry.ID, "error", err)
		}
	}
}

// finish acknowledges and deletes a processed inbound entry. Entries never
// outlive their processing, whether or not they parsed.
func (b *Broker) finish(ctx context.Context, state *channelState, id string) {
	in := state.name + ":in"
	if err := b.redis.XAck(ctx, in, groupName, id).Err(); err != nil {
		b.logger.Warn("ack failed", "channel", state.name, "id", id, "error", err)
	}
	if err := b.redis.XDel(ctx, in, id).Err(); err != nil {
		b.logger.Warn("delete failed", "channel", state.name, "id", id, "error", err)
	}
}

func (b *Broker) readCommands(ctx context.Context, state *channelState) {
	in := state.name + ":in"
	for ctx.Err() == nil {
		if member, err := b.redis.SIsMember(ctx, channelsKey, state.name).Result(); err == nil && !member {
			b.forget(ctx, state)
			return
		}
		streams, err := b.redis.XReadGroup(ctx, &redis.XReadGroupArgs{
			Group: groupName, Consumer: b.consumer, Streams: []string{in, ">"}, Count: 10, Block: time.Second,
		}).Result()
		if err != nil {
			if err != redis.Nil && ctx.Err() == nil {
				b.logger.Warn("read commands failed", "channel", state.name, "error", err)
				time.Sleep(time.Second)
			}
			continue
		}
		for _, stream := range streams {
			for _, message := range stream.Messages {
				b.handle(ctx, state, message)
			}
		}
	}
}

// forget drops a channel that PHP removed from the live set. Once its runner has
// exited, the channel's streams are deleted: a finished channel keeps no transcript in Redis.
func (b *Broker) forget(ctx context.Context, state *channelState) {
	b.kill(ctx, state)
	b.mu.Lock()
	delete(b.channels, state.name)
	b.mu.Unlock()

	for deadline := time.Now().Add(forgetWait); time.Now().Before(deadline) && ctx.Err() == nil; time.Sleep(50 * time.Millisecond) {
		state.mu.Lock()
		running := state.process != nil
		state.mu.Unlock()
		if !running {
			break
		}
	}
	if err := b.redis.Del(context.Background(), state.name+":in", state.name+":out", state.name+":dead").Err(); err != nil {
		b.logger.Warn("delete channel streams failed", "channel", state.name, "error", err)
	}
	b.redis.HDel(context.Background(), containersKey, state.name)
}

func (b *Broker) handle(ctx context.Context, state *channelState, message redis.XMessage) {
	raw, _ := message.Values["command"].(string)
	var cmd command
	if err := json.Unmarshal([]byte(raw), &cmd); err != nil {
		b.logger.Warn("bad command", "channel", state.name, "error", err)
		b.finish(ctx, state, message.ID)
		return
	}
	if cmd.Type == "start_runner" {
		b.startRunner(ctx, state, cmd)
	} else {
		b.dispatch(ctx, state, cmd)
	}
	b.finish(ctx, state, message.ID)
}

func (b *Broker) startRunner(ctx context.Context, state *channelState, cmd command) {
	b.kill(ctx, state)
	if cmd.Container != "" && cmd.Container != state.container {
		state.container = cmd.Container
		b.redis.HSet(ctx, containersKey, state.name, cmd.Container)
	}
	if err := b.spawner.KillAllRunners(ctx, state.container); err != nil {
		b.logger.Warn("kill before start failed", "channel", state.name, "error", err)
	}
	start := append([]byte(nil), cmd.Start...)
	state.mu.Lock()
	state.generation++
	generation := state.generation
	state.mu.Unlock()
	process, err := b.spawner.Start(ctx, state.container, cmd.UID, cmd.Cwd, state.name)
	if err != nil {
		b.logger.Error("start runner failed", "channel", state.name, "error", err)
		b.emitBroker(state, "broker.runner_exited", map[string]any{"generation": generation, "exit_code": -1})
		return
	}
	state.mu.Lock()
	state.process = process
	state.placeholder = placeholderOf(cmd.Start)
	state.mu.Unlock()

	_, err = process.Stdin().Write(append(start, '\n'))
	if err != nil {
		b.logger.Warn("write start line failed", "channel", state.name, "error", err)
	}
	b.emitBroker(state, "broker.runner_started", map[string]any{"generation": generation})
	b.wg.Add(1)
	go func() {
		defer b.wg.Done()
		b.pump(state, process, generation)
	}()
}

// placeholderOf reads the auth proxy placeholder out of a start line.
func placeholderOf(start json.RawMessage) string {
	var line struct {
		Placeholder string `json:"placeholder"`
	}
	_ = json.Unmarshal(start, &line)
	return line.Placeholder
}

// revoke forgets the proxy's cached credential of the runner that just ended or was killed.
func (b *Broker) revoke(state *channelState) {
	state.mu.Lock()
	placeholder := state.placeholder
	state.placeholder = ""
	state.mu.Unlock()
	b.revoker.Revoke(placeholder)
}

// pump copies runner stdout to the out stream, then reports the exit.
func (b *Broker) pump(state *channelState, process Process, generation int) {
	readLines(process.Stdout(), func(line []byte) { b.emitLine(state, generation, string(line), false) })
	code, _ := process.Wait()
	b.emitBroker(state, "broker.runner_exited", map[string]any{"generation": generation, "exit_code": code})
	state.mu.Lock()
	ended := state.process == process
	if ended {
		state.process = nil
	}
	state.mu.Unlock()
	if ended {
		b.revoke(state)
	}
}

func (b *Broker) dispatch(ctx context.Context, state *channelState, cmd command) {
	switch cmd.Type {
	case "turn", "steer":
		if !b.write(state, userMessage{Type: "user", UUID: cmd.UUID, Text: cmd.Text, Priority: cmd.Priority}) {
			b.emitBroker(state, "broker.no_runner", map[string]any{"uuid": cmd.UUID})
		}
	case "tool_result":
		if !b.write(state, toolResult{Type: "tool_result", CallID: cmd.CallID, IsError: cmd.IsError, Content: cmd.Content}) {
			b.emitBroker(state, "broker.no_runner", map[string]any{"call_id": cmd.CallID})
		}
	case "interrupt":
		b.write(state, map[string]string{"type": "interrupt"})
	case "end":
		b.write(state, map[string]string{"type": "end"})
	case "set_model":
		b.write(state, map[string]string{"type": "set_model", "model": cmd.Model})
	case "kill":
		b.kill(ctx, state)
		b.emitBroker(state, "broker.kill_done", nil)
	default:
		b.logger.Warn("unknown command", "channel", state.name, "type", cmd.Type)
	}
}

// write sends one JSON line to the runner and reports whether a runner took it.
func (b *Broker) write(state *channelState, message any) bool {
	encoded, _ := json.Marshal(message)
	state.mu.Lock()
	process := state.process
	state.mu.Unlock()
	if process == nil {
		return false
	}
	if _, err := process.Stdin().Write(append(encoded, '\n')); err != nil {
		b.logger.Warn("write to runner failed", "channel", state.name, "error", err)
		return false
	}
	return true
}

// kill kills the runner's process group inside the container.
func (b *Broker) kill(ctx context.Context, state *channelState) {
	state.mu.Lock()
	process := state.process
	state.mu.Unlock()
	if process == nil || state.container == "" {
		return
	}
	b.revoke(state)
	if err := b.spawner.KillAllRunners(ctx, state.container); err != nil {
		b.logger.Warn("kill failed", "channel", state.name, "error", err)
	}
	_ = process.Stdin().Close()
}

func (b *Broker) killAll() {
	b.mu.Lock()
	states := make([]*channelState, 0, len(b.channels))
	for _, state := range b.channels {
		states = append(states, state)
	}
	b.mu.Unlock()
	ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
	defer cancel()
	for _, state := range states {
		b.kill(ctx, state)
	}
}

// emitBroker appends a broker-originated line `{"type":lineType,...fields}`.
// A "generation" field also stamps the entry; otherwise the current generation does.
func (b *Broker) emitBroker(state *channelState, lineType string, fields map[string]any) {
	state.mu.Lock()
	generation := state.generation
	state.mu.Unlock()
	if value, ok := fields["generation"].(int); ok {
		generation = value
	}
	typeJSON, _ := json.Marshal(lineType)
	line := `{"type":` + string(typeJSON) + `}`
	if len(fields) > 0 {
		body, _ := json.Marshal(fields)
		line = `{"type":` + string(typeJSON) + `,` + string(body[1:])
	}
	b.emitLine(state, generation, line, true)
}

// emitLine appends one line to the out stream. The origin field is set only on
// the broker's own lines; a runner cannot write stream fields, only the line body,
// so the thread driver trusts the field and never the body's claims.
func (b *Broker) emitLine(state *channelState, generation int, line string, fromBroker bool) {
	state.mu.Lock()
	defer state.mu.Unlock()
	state.sequence++
	values := map[string]any{"generation": generation, "sequence": state.sequence, "line": line}
	if fromBroker {
		values["origin"] = "broker"
	}
	err := b.redis.XAdd(context.Background(), &redis.XAddArgs{Stream: state.name + ":out", Values: values, MaxLen: outStreamMaxLength, Approx: true}).Err()
	if err != nil {
		b.logger.Error("append out entry failed", "channel", state.name, "error", err)
	}
}

func toInt(value any) int {
	text, _ := value.(string)
	number, _ := strconv.Atoi(text)
	return number
}
