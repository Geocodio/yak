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
