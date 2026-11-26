package hook

import (
	"errors"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
)

// maxAncestorDepth bounds the walk up from the hook to its claude.
const maxAncestorDepth = 6

// ProcessAlive reports whether a process with this id exists.
func ProcessAlive(pid int) bool {
	err := syscall.Kill(pid, 0)
	return !errors.Is(err, syscall.ESRCH)
}

// FindClaudePID returns the first ancestor of this process that is neither a shell nor yak: the plugin starts the
// hook through yak's launcher script, possibly inside sh -c, so that process is the claude running the hook.
// The process name is not compared with "claude" because installed builds are named after their version.
func FindClaudePID() int {
	pid := os.Getppid()
	for range maxAncestorDepth {
		out, err := exec.Command("ps", "-o", "ppid=,comm=", "-p", strconv.Itoa(pid)).Output()
		if err != nil {
			return 0
		}
		parent, name, ok := strings.Cut(strings.TrimSpace(string(out)), " ")
		if !ok {
			return 0
		}
		switch filepath.Base(strings.TrimSpace(strings.TrimPrefix(name, "-"))) {
		case "sh", "bash", "zsh", "dash", "fish", "yak":
			next, err := strconv.Atoi(parent)
			if err != nil || next <= 1 {
				return 0
			}
			pid = next
		default:
			return pid
		}
	}
	return 0
}
