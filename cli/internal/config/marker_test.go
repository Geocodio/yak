package config

import (
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

const markerSID = "4f1c0c9e-1111-4222-8333-444455556666"

func sampleMarker() Marker {
	return Marker{ThreadID: 42, TeleportID: "01J8Z", Owner: "work mac", ThreadURL: "https://yak.test/threads/42",
		StackMessagesURL: "https://yak.test/api/cli/v1/threads/42/stack-messages", BaseURL: "https://yak.test",
		WrittenAt: time.Date(2026, 11, 11, 9, 0, 0, 0, time.UTC)}
}

func TestMarkerRoundTripPrivate(t *testing.T) {
	dir := t.TempDir()
	if err := WriteMarker(dir, markerSID, sampleMarker()); err != nil {
		t.Fatal(err)
	}
	got, ok := ReadMarker(dir, markerSID)
	if !ok || got != sampleMarker() {
		t.Fatalf("%v %+v", ok, got)
	}
	info, err := os.Stat(filepath.Join(dir, "markers", markerSID+".json"))
	if err != nil || info.Mode().Perm() != 0o600 {
		t.Fatalf("%v %v", err, info.Mode())
	}
	if err := ClearMarker(dir, markerSID); err != nil {
		t.Fatal(err)
	}
	if _, ok := ReadMarker(dir, markerSID); ok {
		t.Fatal("marker survived clear")
	}
	if err := ClearMarker(dir, markerSID); err != nil {
		t.Fatalf("clearing twice: %v", err)
	}
}

func TestMarkerRejectsNonUUIDSessionID(t *testing.T) {
	dir := t.TempDir()
	for _, id := range []string{"../x", "", "abc", markerSID + "/..", "4f1c0c9e-1111-4222-8333-44445555666z"} {
		if WriteMarker(dir, id, sampleMarker()) == nil {
			t.Errorf("%q written", id)
		}
		if _, ok := ReadMarker(dir, id); ok {
			t.Errorf("%q read", id)
		}
		if WriteNote(dir, id, "x") == nil {
			t.Errorf("%q note written", id)
		}
		if _, ok := TakeNote(dir, id); ok {
			t.Errorf("%q note taken", id)
		}
	}
	entries, _ := os.ReadDir(dir)
	if len(entries) != 0 {
		t.Fatalf("files created: %v", entries)
	}
}

func TestTruncatedOrOversizedMarkerReadsAsMissing(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "markers", markerSID+".json")
	os.MkdirAll(filepath.Dir(path), 0o700)
	cases := map[string]string{
		"truncated":  `{"thread_id": 42, "teleport_id": "01J`,
		"wrong type": `{"thread_id": "forty-two", "teleport_id": "x"}`,
		"not json":   "hello",
		"no thread":  `{"teleport_id": "x"}`,
		"oversized":  `{"thread_id": 1, "teleport_id": "x", "owner": "` + strings.Repeat("a", 5000) + `"}`,
		"empty":      "",
	}
	for name, content := range cases {
		os.WriteFile(path, []byte(content), 0o600)
		if _, ok := ReadMarker(dir, markerSID); ok {
			t.Errorf("%s accepted", name)
		}
	}
}

func TestTakeNoteDeletesAfterRead(t *testing.T) {
	dir := t.TempDir()
	if _, ok := TakeNote(dir, markerSID); ok {
		t.Fatal("note from nothing")
	}
	if err := WriteNote(dir, markerSID, "hello note"); err != nil {
		t.Fatal(err)
	}
	if text, ok := TakeNote(dir, markerSID); !ok || text != "hello note" {
		t.Fatalf("%q %v", text, ok)
	}
	if _, ok := TakeNote(dir, markerSID); ok {
		t.Fatal("note printed twice")
	}
}
