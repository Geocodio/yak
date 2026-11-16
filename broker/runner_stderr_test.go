package main

import (
	"bytes"
	"log/slog"
	"strings"
	"testing"
)

func TestStderrLogWritesOneRecordPerLine(t *testing.T) {
	var output bytes.Buffer
	log := &stderrLog{channel: "runs:7", logger: slog.New(slog.NewJSONHandler(&output, nil))}

	log.Write([]byte("first li"))
	log.Write([]byte("ne\nsecond line\n\n"))

	records := strings.Split(strings.TrimSpace(output.String()), "\n")
	if len(records) != 2 {
		t.Fatalf("expected 2 records, got %d: %s", len(records), output.String())
	}
	if !strings.Contains(records[0], `"line":"first line"`) || !strings.Contains(records[0], `"channel":"runs:7"`) {
		t.Fatalf("unexpected first record: %s", records[0])
	}
	if !strings.Contains(records[1], `"line":"second line"`) {
		t.Fatalf("unexpected second record: %s", records[1])
	}
}

func TestStderrLogCutsLongLines(t *testing.T) {
	var output bytes.Buffer
	log := &stderrLog{channel: "runs:7", logger: slog.New(slog.NewJSONHandler(&output, nil))}

	log.Write([]byte(strings.Repeat("x", maxStderrLine+500)))

	if strings.Count(output.String(), "x") > maxStderrLine {
		t.Fatalf("line was not cut to %d bytes", maxStderrLine)
	}
}

func TestRunnerArgsDropToTheSandboxUserWithItsGroups(t *testing.T) {
	args := strings.Join(runnerArgs("yak-thread-7", 1001, "/workspace", "threads:7"), " ")
	want := "exec yak-thread-7 --cwd /workspace --env HOME=/home/yak -- setpriv --reuid=1001 --regid=1001 --init-groups -- setsid --wait node /opt/yak-runner/runner.mjs --channel"
	if !strings.HasPrefix(args, want) {
		t.Fatalf("unexpected args: %s", args)
	}
	if strings.Contains(args, "--user") {
		t.Fatalf("runner must not use incus --user: %s", args)
	}
}
