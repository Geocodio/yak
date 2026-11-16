package main

import (
	"bufio"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"os/exec"
	"strconv"
	"strings"
)

const runnerPath = "/opt/yak-runner/runner.mjs"

// Process is one running runner: the pipes of an `incus exec` session.
type Process interface {
	Stdin() io.WriteCloser
	Stdout() io.Reader
	Wait() (exitCode int, err error)
}

// Spawner starts runners and kills them: one channel's runner, or every runner in a container.
type Spawner interface {
	Start(ctx context.Context, container string, uid int, cwd, channel string) (Process, error)
	KillRunner(ctx context.Context, container, channel string) error
	KillAllRunners(ctx context.Context, container string) error
}

// IncusSpawner runs the runner inside an Incus container through the incus CLI.
type IncusSpawner struct{}

type incusProcess struct {
	cmd    *exec.Cmd
	stdin  io.WriteCloser
	stdout io.Reader
}

func (p *incusProcess) Stdin() io.WriteCloser { return p.stdin }
func (p *incusProcess) Stdout() io.Reader     { return p.stdout }

func (p *incusProcess) Wait() (int, error) {
	err := p.cmd.Wait()
	if err == nil {
		return 0, nil
	}
	if exitErr, ok := err.(*exec.ExitError); ok {
		return exitErr.ExitCode(), nil
	}
	return -1, err
}

// Start runs the runner under setsid so the runner leads its own session and
// the whole process group (including the claude child) can be killed together.
// The context is deliberately not bound to the command: a broker shutdown
// kills runners explicitly through KillAllRunners. The --channel argument marks which
// channel the runner serves, so a single runner can be found by its command line.
func (IncusSpawner) Start(_ context.Context, container string, uid int, cwd, channel string) (Process, error) {
	cmd := exec.Command("incus", runnerArgs(container, uid, cwd, channel)...)
	stdin, err := cmd.StdinPipe()
	if err != nil {
		return nil, err
	}
	stdout, err := cmd.StdoutPipe()
	if err != nil {
		return nil, err
	}
	cmd.Stderr = &stderrLog{channel: sanitizeChannel(channel), logger: slog.Default()}
	if err := cmd.Start(); err != nil {
		return nil, err
	}
	return &incusProcess{cmd: cmd, stdin: stdin, stdout: stdout}, nil
}

// killScript kills every runner whose command line matches the pgrep pattern, together with
// all its descendants and its session. The agent's tool processes (a Bash call, its children)
// run in sessions of their own, so the session kill alone would leave them running as orphans.
// The bracketed pattern keeps pgrep from matching the shell that runs the script.
func killScript(pattern string) string {
	return `descendants() { for child in $(pgrep -P "$1"); do echo "$child"; descendants "$child"; done; }
for pid in $(pgrep -f '` + pattern + `'); do
  session="$(ps -o sid= -p "$pid" | tr -d ' ')"
  family="$pid $(descendants "$pid")"
  kill -KILL $family 2>/dev/null
  if [ -n "$session" ]; then pkill -KILL -s "$session"; fi
done
true`
}

// killAllRunnersScript kills every runner in the container.
var killAllRunnersScript = killScript(`[/]opt/yak-runner/runner.mjs`)

// killRunnerScript kills only the runner serving one channel. The pattern is anchored at the
// end of the command line so channel threads_1 never matches threads_12.
func killRunnerScript(channel string) string {
	return killScript(`[/]opt/yak-runner/runner.mjs --channel ` + sanitizeChannel(channel) + `$`)
}

// KillRunner kills the runner of one channel in the container, leaving other runners alone.
func (IncusSpawner) KillRunner(ctx context.Context, container, channel string) error {
	return incusKill(ctx, container, killRunnerScript(channel))
}

func incusKill(ctx context.Context, container, script string) error {
	out, err := exec.CommandContext(ctx, "incus", "exec", container, "--", "sh", "-c", script).CombinedOutput()
	if err != nil {
		return fmt.Errorf("kill runners in %s: %w: %s", container, err, strings.TrimSpace(string(out)))
	}
	return nil
}

// sanitizeChannel replaces every byte outside [A-Za-z0-9_-] with an underscore.
func sanitizeChannel(name string) string {
	sanitized := []byte(name)
	for index, char := range sanitized {
		isSafe := char >= 'a' && char <= 'z' || char >= 'A' && char <= 'Z' || char >= '0' && char <= '9' || char == '_' || char == '-'
		if !isSafe {
			sanitized[index] = '_'
		}
	}
	return string(sanitized)
}

// KillAllRunners kills every runner in the container, its session and every process below it.
func (IncusSpawner) KillAllRunners(ctx context.Context, container string) error {
	return incusKill(ctx, container, killAllRunnersScript)
}

// maxLineBytes is the longest runner stdout line the broker forwards. A longer line is
// consumed and dropped, never held whole in memory.
const maxLineBytes = 8 << 20

// readLines calls emit for every newline-terminated line of at most maxLineBytes. A longer
// line is read in chunks and discarded, and onDropped gets its full size instead.
// A final line without a newline is handled the same way.
func readLines(reader io.Reader, emit func(line []byte), onDropped func(bytes int)) {
	buffered := bufio.NewReaderSize(reader, 64*1024)
	var line []byte
	dropped, isDropping := 0, false
	for {
		chunk, isPrefix, err := buffered.ReadLine()
		if isDropping {
			dropped += len(chunk)
		} else if len(line)+len(chunk) > maxLineBytes {
			isDropping, dropped, line = true, len(line)+len(chunk), nil
		} else {
			line = append(line, chunk...)
		}
		if !isPrefix {
			if isDropping {
				onDropped(dropped)
			} else if len(line) > 0 {
				emit(line)
			}
			line, dropped, isDropping = nil, 0, false
		}
		if err != nil {
			return
		}
	}
}

// ContainerAddress returns the container's IPv4 address on its bridge.
func (IncusSpawner) ContainerAddress(ctx context.Context, container string) (string, error) {
	out, err := exec.CommandContext(ctx, "incus", "query", "/1.0/instances/"+container+"/state").Output()
	if err != nil {
		return "", fmt.Errorf("read state of %s: %w", container, err)
	}
	return firstGlobalIPv4(out)
}

// sandboxInterface is the NIC the default Incus profile gives every sandbox. A sandbox that runs
// Docker has further interfaces (docker0, veth pairs) whose addresses are not its bridge address.
const sandboxInterface = "eth0"

// firstGlobalIPv4 returns the sandbox NIC's global IPv4 address from an Incus instance state.
func firstGlobalIPv4(state []byte) (string, error) {
	var parsed struct {
		Network map[string]struct {
			Addresses []struct {
				Family  string `json:"family"`
				Address string `json:"address"`
				Scope   string `json:"scope"`
			} `json:"addresses"`
		} `json:"network"`
	}
	if err := json.Unmarshal(state, &parsed); err != nil {
		return "", err
	}
	for _, address := range parsed.Network[sandboxInterface].Addresses {
		if address.Family == "inet" && address.Scope == "global" {
			return address.Address, nil
		}
	}
	return "", fmt.Errorf("no global IPv4 address on %s", sandboxInterface)
}

// stderrLog writes a runner's stderr to the broker log, one record per line, so a runner
// that dies before it can report on stdout still leaves a trace. Lines are cut to
// maxStderrLine bytes; no real credential exists inside a sandbox to leak.
type stderrLog struct {
	channel string
	logger  *slog.Logger
	partial []byte
}

const maxStderrLine = 2000

func (l *stderrLog) Write(chunk []byte) (int, error) {
	l.partial = append(l.partial, chunk...)
	for {
		index := strings.IndexByte(string(l.partial), '\n')
		if index < 0 {
			break
		}
		l.emit(l.partial[:index])
		l.partial = l.partial[index+1:]
	}
	if len(l.partial) > maxStderrLine {
		l.emit(l.partial)
		l.partial = nil
	}
	return len(chunk), nil
}

func (l *stderrLog) emit(line []byte) {
	text := strings.TrimSpace(string(line))
	if text == "" {
		return
	}
	if len(text) > maxStderrLine {
		text = text[:maxStderrLine]
	}
	l.logger.Warn("runner stderr", "channel", l.channel, "line", text)
}

// runnerArgs starts the runner as the sandbox user with that user's own group and its
// supplementary groups (docker among them). `incus exec --user` alone switches only the
// uid, which leaves the process in group root and outside the docker group.
func runnerArgs(container string, uid int, cwd, channel string) []string {
	id := strconv.Itoa(uid)
	return []string{"exec", container, "--cwd", cwd, "--env", "HOME=/home/yak", "--",
		"setpriv", "--reuid=" + id, "--regid=" + id, "--init-groups", "--",
		"setsid", "--wait", "node", runnerPath, "--channel", sanitizeChannel(channel)}
}
