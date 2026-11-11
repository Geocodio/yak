package main

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func digestOf(token string) string {
	sum := sha256.Sum256([]byte(token))
	return hex.EncodeToString(sum[:])
}

func writeCredentials(t *testing.T, content string) string {
	t.Helper()
	path := filepath.Join(t.TempDir(), ".credentials.json")
	if err := os.WriteFile(path, []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}
	return path
}

type countingChecker struct {
	calls int
	inner ExposureChecker
}

func (c *countingChecker) Check(path string) error { c.calls++; return c.inner.Check(path) }

func TestCheckerAllowsMissingFile(t *testing.T) {
	if err := NewFileExposureChecker(nil).Check(filepath.Join(t.TempDir(), "none")); err != nil {
		t.Fatal(err)
	}
}

func TestCheckerAllowsMcpOnlyCredentials(t *testing.T) {
	path := writeCredentials(t, `{"mcpOAuth":{"x":{"accessToken":"a"}}}`)
	if err := NewFileExposureChecker(nil).Check(path); err != nil {
		t.Fatal(err)
	}
}

func TestCheckerAllowsSharedAccountByHash(t *testing.T) {
	path := writeCredentials(t, `{"claudeAiOauth":{"refreshToken":"shared-token"}}`)
	if err := NewFileExposureChecker([]string{"", digestOf("shared-token")}).Check(path); err != nil {
		t.Fatal(err)
	}
}

func TestCheckerRefusesOtherClaudeOauth(t *testing.T) {
	path := writeCredentials(t, `{"claudeAiOauth":{"refreshToken":"personal"}}`)
	if err := NewFileExposureChecker([]string{digestOf("shared-token")}).Check(path); err != errCredentialExposure {
		t.Fatalf("err = %v", err)
	}
}

func TestCheckerRefusesUnparseableFile(t *testing.T) {
	path := writeCredentials(t, `not json`)
	if err := NewFileExposureChecker(nil).Check(path); err != errCredentialExposure {
		t.Fatalf("err = %v", err)
	}
}

func TestCheckerRefusesUnreadableFile(t *testing.T) {
	if err := NewFileExposureChecker(nil).Check(t.TempDir()); err != errCredentialExposure {
		t.Fatalf("err = %v", err)
	}
}

func startCommand(extra string) string {
	return `{"type":"start_runner","container":"c1","uid":1001,"cwd":"/w",` + extra + `"start":{"type":"start"}}`
}

func requireRefusalLine(t *testing.T, entries []map[string]any, reason string, generation float64) {
	t.Helper()
	var line map[string]any
	if err := json.Unmarshal([]byte(findEntry(entries, "broker.start_refused")["line"].(string)), &line); err != nil {
		t.Fatal(err)
	}
	if len(line) != 3 || line["type"] != "broker.start_refused" || line["reason"] != reason || line["generation"] != generation {
		t.Fatalf("line = %v", line)
	}
}

func requireRefusal(t *testing.T, h *harness, reason string) {
	t.Helper()
	entries := h.out("threads:1", func(e []map[string]any) bool { return countLines(e, "broker.start_refused") == 1 })
	requireRefusalLine(t, entries, reason, 1)
	if h.spawner.startCount() != 0 {
		t.Fatal("a runner was started")
	}
}

func TestStartRunnerRefusedWhenCredentialsUnreadable(t *testing.T) {
	h := newHarness(t, func(h *harness) { h.options = []Option{WithExposureChecker(NewFileExposureChecker(nil))} })
	h.command("threads:1", startCommand(`"credentials_path":"`+t.TempDir()+`",`))
	requireRefusal(t, h, "credential_exposure")
}

func TestStartRunnerRefusedWhenExposed(t *testing.T) {
	path := writeCredentials(t, `{"claudeAiOauth":{"refreshToken":"personal"}}`)
	h := newHarness(t, func(h *harness) { h.options = []Option{WithExposureChecker(NewFileExposureChecker(nil))} })
	h.command("threads:1", startCommand(`"credentials_path":"`+path+`",`))
	requireRefusal(t, h, "credential_exposure")
}

func TestCredentialsPathFallsBackToEnv(t *testing.T) {
	t.Setenv("YAK_BROKER_CREDENTIALS_FILE", writeCredentials(t, `{"claudeAiOauth":{"refreshToken":"personal"}}`))
	h := newHarness(t, func(h *harness) { h.options = []Option{WithExposureChecker(NewFileExposureChecker(nil))} })
	h.command("threads:1", startCommand(""))
	requireRefusal(t, h, "credential_exposure")
}

func TestCheckSkippedWhenBothPathsEmpty(t *testing.T) {
	t.Setenv("YAK_BROKER_CREDENTIALS_FILE", "")
	checker := &countingChecker{inner: NewFileExposureChecker(nil)}
	h := newHarness(t, func(h *harness) { h.options = []Option{WithExposureChecker(checker)} })
	h.command("threads:1", startCommand(""))
	waitFor(t, func() bool { return h.spawner.startCount() == 1 })
	if checker.calls != 0 {
		t.Fatalf("checker called %d times", checker.calls)
	}
}

func TestRefusalNeverLogsTokenText(t *testing.T) {
	path := writeCredentials(t, `{"claudeAiOauth":{"refreshToken":"SECRETTOKENTEXT"}}`)
	h := newHarness(t, func(h *harness) { h.options = []Option{WithExposureChecker(NewFileExposureChecker(nil))} })
	h.command("threads:1", startCommand(`"credentials_path":"`+path+`",`))
	requireRefusal(t, h, "credential_exposure")
	if strings.Contains(strings.Join(h.logs.Lines(), "\n"), "SECRETTOKENTEXT") {
		t.Fatal("token text was logged")
	}
}

func TestLogsNeverContainStartLineOrPlaceholder(t *testing.T) {
	h := newHarness(t)
	h.command("threads:1", `{"type":"start_runner","container":"c1","uid":1,"cwd":"/w","start":{"type":"start","placeholder":"yakph-SECRETVALUE"}}`)
	h.command("threads:1", `{"type":"bad yakph-SECRETVALUE`)
	h.out("threads:1", func(e []map[string]any) bool { return countLines(e, "runner_started") == 1 })
	waitFor(t, func() bool { return strings.Contains(strings.Join(h.logs.Lines(), "\n"), "bad command") })
	if strings.Contains(strings.Join(h.logs.Lines(), "\n"), "SECRETVALUE") {
		t.Fatal("placeholder was logged")
	}
}

func TestStartRunnerRefusedPastCap(t *testing.T) {
	now := time.Unix(1000, 0)
	h := newHarness(t, func(h *harness) {
		h.options = []Option{WithRestartCap(2, time.Minute, func() time.Time { return now })}
	})
	for i := 0; i < 3; i++ {
		h.command("threads:1", startCommand(""))
	}
	entries := h.out("threads:1", func(e []map[string]any) bool { return countLines(e, "broker.start_refused") == 1 })
	requireRefusalLine(t, entries, "restart_cap", 3)
	if h.spawner.startCount() != 2 {
		t.Fatalf("starts = %d", h.spawner.startCount())
	}
}
