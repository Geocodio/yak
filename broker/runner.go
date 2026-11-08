package main

import (
	"bufio"
	"context"
	"encoding/json"
	"fmt"
	"io"
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

// Spawner starts runners and kills the ones left in a container.
type Spawner interface {
	Start(ctx context.Context, container string, uid int, cwd string) (Process, error)
	KillRunners(ctx context.Context, container string) error
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
// kills runners explicitly through KillRunners.
func (IncusSpawner) Start(_ context.Context, container string, uid int, cwd string) (Process, error) {
	cmd := exec.Command("incus", "exec", container,
		"--user", strconv.Itoa(uid), "--cwd", cwd, "--env", "HOME=/home/yak",
		"--", "setsid", "--wait", "node", runnerPath)
	stdin, err := cmd.StdinPipe()
	if err != nil {
		return nil, err
	}
	stdout, err := cmd.StdoutPipe()
	if err != nil {
		return nil, err
	}
	if err := cmd.Start(); err != nil {
		return nil, err
	}
	return &incusProcess{cmd: cmd, stdin: stdin, stdout: stdout}, nil
}

// killRunnersScript kills every runner together with all its descendants and its
// session. The agent's tool processes (a Bash call, its children) run in sessions of
// their own, so the session kill alone would leave them running as orphans. The
// bracketed pattern keeps pgrep from matching the shell that runs the script.
const killRunnersScript = `descendants() { for child in $(pgrep -P "$1"); do echo "$child"; descendants "$child"; done; }
for pid in $(pgrep -f '[/]opt/yak-runner/runner.mjs'); do
  session="$(ps -o sid= -p "$pid" | tr -d ' ')"
  family="$pid $(descendants "$pid")"
  kill -KILL $family 2>/dev/null
  if [ -n "$session" ]; then pkill -KILL -s "$session"; fi
done
true`

// KillRunners kills every runner in the container, its session and every process below it.
func (IncusSpawner) KillRunners(ctx context.Context, container string) error {
	out, err := exec.CommandContext(ctx, "incus", "exec", container, "--", "sh", "-c", killRunnersScript).CombinedOutput()
	if err != nil {
		return fmt.Errorf("kill runners in %s: %w: %s", container, err, strings.TrimSpace(string(out)))
	}
	return nil
}

// readLines calls emit for every newline-terminated line, with no size limit.
// A final line without a newline is emitted too.
func readLines(reader io.Reader, emit func(line []byte)) {
	buffered := bufio.NewReaderSize(reader, 64*1024)
	for {
		line, err := buffered.ReadBytes('\n')
		if trimmed := strings.TrimRight(string(line), "\r\n"); trimmed != "" {
			emit([]byte(trimmed))
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
