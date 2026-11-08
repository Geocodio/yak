package main

import (
	"context"
	"encoding/json"
	"fmt"
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
	containersKey = "broker:containers"
	groupName     = "broker"
)

type command struct {
	Type      string          `json:"type"`
	Container string          `json:"container"`
	UID       int             `json:"uid"`
	Cwd       string          `json:"cwd"`
	Start     json.RawMessage `json:"start"`
	// CredentialReference is a one-time reference the broker redeems for the credential.
	CredentialReference string `json:"credential_reference"`
	UUID                string `json:"uuid"`
	Text                string `json:"text"`
	Priority            string `json:"priority"`
	Model               string `json:"model"`
}

type userMessage struct {
	Type     string `json:"type"`
	UUID     string `json:"uuid"`
	Text     string `json:"text"`
	Priority string `json:"priority,omitempty"`
}

// channelState is the broker's view of one channel. mu orders sequence
// assignment together with the XADD so entries land in sequence order.
type channelState struct {
	name       string
	mu         sync.Mutex
	container  string
	generation int
	sequence   int
	process    Process
}

type Broker struct {
	redis    *redis.Client
	spawner  Spawner
	redeemer Redeemer
	logger   *slog.Logger
	consumer string

	mu       sync.Mutex
	channels map[string]*channelState
	wg       sync.WaitGroup
}

func New(client *redis.Client, spawner Spawner, redeemer Redeemer, logger *slog.Logger) *Broker {
	hostname, _ := os.Hostname()
	return &Broker{redis: client, spawner: spawner, redeemer: redeemer, logger: logger, consumer: hostname, channels: map[string]*channelState{}}
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
			if err := b.spawner.KillRunners(ctx, state.container); err != nil {
				b.logger.Warn("startup kill failed", "channel", name, "error", err)
			}
		}
		b.emit(state, `{"type":"broker","event":"broker_started"}`)
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
		_ = b.redis.XGroupSetID(ctx, in, groupName, "$").Err()
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

// forget drops a channel that PHP removed from the live set.
func (b *Broker) forget(ctx context.Context, state *channelState) {
	b.kill(ctx, state)
	b.mu.Lock()
	delete(b.channels, state.name)
	b.mu.Unlock()
}

func (b *Broker) handle(ctx context.Context, state *channelState, message redis.XMessage) {
	raw, _ := message.Values["command"].(string)
	var cmd command
	if err := json.Unmarshal([]byte(raw), &cmd); err != nil {
		b.logger.Warn("bad command", "channel", state.name, "error", err)
		b.redis.XAck(ctx, state.name+":in", groupName, message.ID)
		return
	}
	in := state.name + ":in"
	if cmd.Type == "start_runner" {
		// The entry carries a credential, so it is deleted before anything else.
		b.redis.XDel(ctx, in, message.ID)
		b.startRunner(ctx, state, cmd)
	} else {
		b.dispatch(ctx, state, cmd)
	}
	b.redis.XAck(ctx, in, groupName, message.ID)
}

func (b *Broker) startRunner(ctx context.Context, state *channelState, cmd command) {
	b.kill(ctx, state)
	if cmd.Container != "" && cmd.Container != state.container {
		state.container = cmd.Container
		b.redis.HSet(ctx, containersKey, state.name, cmd.Container)
	}
	if err := b.spawner.KillRunners(ctx, state.container); err != nil {
		b.logger.Warn("kill before start failed", "channel", state.name, "error", err)
	}
	start, err := b.startLine(ctx, cmd)
	if err != nil {
		b.logger.Error("build start line failed", "channel", state.name, "error", err)
		b.failStart(state)
		return
	}
	process, err := b.spawner.Start(ctx, state.container, cmd.UID, cmd.Cwd)
	if err != nil {
		b.logger.Error("start runner failed", "channel", state.name, "error", err)
		b.failStart(state)
		return
	}
	state.mu.Lock()
	state.generation++
	generation := state.generation
	state.process = process
	state.mu.Unlock()

	_, err = process.Stdin().Write(append(start, '\n'))
	clear(start)
	if err != nil {
		b.logger.Warn("write start line failed", "channel", state.name, "error", err)
	}
	b.wg.Add(1)
	go func() {
		defer b.wg.Done()
		b.pump(state, process, generation)
	}()
}

// failStart reports a runner that could not be started as an exit of the generation it would have had.
func (b *Broker) failStart(state *channelState) {
	b.emit(state, fmt.Sprintf(`{"type":"broker","event":"runner_exited","generation":%d,"exit_code":-1}`, state.generation+1))
	state.mu.Lock()
	state.generation++
	state.mu.Unlock()
}

// startLine returns the runner's start line. When the command carries a credential reference,
// the redeemed credential is merged in here, in memory only: it never touches Redis.
func (b *Broker) startLine(ctx context.Context, cmd command) ([]byte, error) {
	if cmd.CredentialReference == "" {
		return append([]byte(nil), cmd.Start...), nil
	}
	credential, err := b.redeemer.Redeem(ctx, cmd.CredentialReference)
	if err != nil {
		return nil, err
	}
	var start map[string]json.RawMessage
	if err := json.Unmarshal(cmd.Start, &start); err != nil {
		return nil, err
	}
	encoded, err := json.Marshal(credential)
	if err != nil {
		return nil, err
	}
	start["credential"] = encoded
	return json.Marshal(start)
}

// pump copies runner stdout to the out stream, then reports the exit.
func (b *Broker) pump(state *channelState, process Process, generation int) {
	readLines(process.Stdout(), func(line []byte) { b.emitLine(state, generation, string(line)) })
	code, _ := process.Wait()
	b.emitLine(state, generation, fmt.Sprintf(`{"type":"broker","event":"runner_exited","generation":%d,"exit_code":%d}`, generation, code))
	state.mu.Lock()
	if state.process == process {
		state.process = nil
	}
	state.mu.Unlock()
}

func (b *Broker) dispatch(ctx context.Context, state *channelState, cmd command) {
	switch cmd.Type {
	case "turn", "steer":
		if !b.write(state, userMessage{Type: "user", UUID: cmd.UUID, Text: cmd.Text, Priority: cmd.Priority}) {
			uuid, _ := json.Marshal(cmd.UUID)
			b.emit(state, `{"type":"broker","event":"no_runner","uuid":`+string(uuid)+`}`)
		}
	case "interrupt":
		b.write(state, map[string]string{"type": "interrupt"})
	case "end":
		b.write(state, map[string]string{"type": "end"})
	case "set_model":
		b.write(state, map[string]string{"type": "set_model", "model": cmd.Model})
	case "kill":
		b.kill(ctx, state)
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
	if err := b.spawner.KillRunners(ctx, state.container); err != nil {
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

// emit appends a broker-originated line under the current generation.
func (b *Broker) emit(state *channelState, line string) {
	state.mu.Lock()
	generation := state.generation
	state.mu.Unlock()
	b.emitLine(state, generation, line)
}

func (b *Broker) emitLine(state *channelState, generation int, line string) {
	state.mu.Lock()
	defer state.mu.Unlock()
	state.sequence++
	err := b.redis.XAdd(context.Background(), &redis.XAddArgs{
		Stream: state.name + ":out",
		Values: map[string]any{"generation": generation, "sequence": state.sequence, "line": line},
	}).Err()
	if err != nil {
		b.logger.Error("append out entry failed", "channel", state.name, "error", err)
	}
}

func toInt(value any) int {
	text, _ := value.(string)
	number, _ := strconv.Atoi(text)
	return number
}
