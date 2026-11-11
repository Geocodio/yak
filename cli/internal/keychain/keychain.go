// Package keychain stores the device token in the operating system keychain.
package keychain

import (
	"bytes"
	"errors"
	"fmt"
	"os/exec"
	"runtime"
	"strings"
	"sync"
)

const service = "yak-cli"

// ErrNotFound means no token is stored for the base URL.
var ErrNotFound = errors.New("keychain: token not found")

// Store keeps one token per Yak base URL.
type Store interface {
	Get(baseURL string) (string, error)
	Set(baseURL, token string) error
	Delete(baseURL string) error
}

// Runner runs an external command and returns its stdout.
type Runner func(name string, stdin string, args ...string) (stdout string, err error)

// ExitError is a non-zero exit of a keychain command.
type ExitError struct {
	Code   int
	Stderr string
}

func (e *ExitError) Error() string {
	return fmt.Sprintf("exit status %d: %s", e.Code, strings.TrimSpace(e.Stderr))
}

func exitCode(err error) (int, bool) {
	var e *ExitError
	if errors.As(err, &e) {
		return e.Code, true
	}
	return 0, false
}

type macOS struct{ run Runner }

// NewMacOS uses the `security` tool.
// ponytail: the token is a -w argument, visible in ps for the duration of the call; the security tool has no stdin form.
func NewMacOS(run Runner) Store { return macOS{run} }

func (m macOS) Get(baseURL string) (string, error) {
	out, err := m.run("security", "", "find-generic-password", "-s", service, "-a", baseURL, "-w")
	if c, ok := exitCode(err); ok && c == 44 {
		return "", ErrNotFound
	}
	if err != nil {
		return "", err
	}
	return strings.TrimSpace(out), nil
}

func (m macOS) Set(baseURL, token string) error {
	_, err := m.run("security", "", "add-generic-password", "-U", "-s", service, "-a", baseURL, "-w", token)
	return err
}

func (m macOS) Delete(baseURL string) error {
	_, err := m.run("security", "", "delete-generic-password", "-s", service, "-a", baseURL)
	if c, ok := exitCode(err); ok && c == 44 {
		return ErrNotFound
	}
	return err
}

type linux struct{ run Runner }

// NewLinux uses `secret-tool`; the token travels on stdin only.
func NewLinux(run Runner) Store { return linux{run} }

func (l linux) Get(baseURL string) (string, error) {
	out, err := l.run("secret-tool", "", "lookup", "service", service, "account", baseURL)
	if c, ok := exitCode(err); ok && c == 1 && out == "" {
		return "", ErrNotFound
	}
	if err != nil {
		return "", err
	}
	if out == "" {
		return "", ErrNotFound
	}
	return strings.TrimSpace(out), nil
}

func (l linux) Set(baseURL, token string) error {
	_, err := l.run("secret-tool", token, "store", "--label", "Yak CLI", "service", service, "account", baseURL)
	return err
}

func (l linux) Delete(baseURL string) error {
	_, err := l.run("secret-tool", "", "clear", "service", service, "account", baseURL)
	return err
}

type memory struct {
	mu     sync.Mutex
	tokens map[string]string
}

// NewMemory is an in-process store for tests.
func NewMemory() Store { return &memory{tokens: map[string]string{}} }

func (m *memory) Get(baseURL string) (string, error) {
	m.mu.Lock()
	defer m.mu.Unlock()
	t, ok := m.tokens[baseURL]
	if !ok {
		return "", ErrNotFound
	}
	return t, nil
}

func (m *memory) Set(baseURL, token string) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	m.tokens[baseURL] = token
	return nil
}

func (m *memory) Delete(baseURL string) error {
	m.mu.Lock()
	defer m.mu.Unlock()
	delete(m.tokens, baseURL)
	return nil
}

func execRunner(name, stdin string, args ...string) (string, error) {
	c := exec.Command(name, args...)
	if stdin != "" {
		c.Stdin = strings.NewReader(stdin)
	}
	var out, errb bytes.Buffer
	c.Stdout, c.Stderr = &out, &errb
	err := c.Run()
	var ee *exec.ExitError
	if errors.As(err, &ee) {
		return out.String(), &ExitError{Code: ee.ExitCode(), Stderr: errb.String()}
	}
	return out.String(), err
}

// NewSystem picks the macOS or Linux store for the running OS.
func NewSystem() Store {
	if runtime.GOOS == "darwin" {
		return NewMacOS(execRunner)
	}
	return NewLinux(execRunner)
}
