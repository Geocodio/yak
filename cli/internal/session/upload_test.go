package session

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func writeFile(t *testing.T, path, content string) {
	t.Helper()
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatal(err)
	}
}

// uploadLog returns "METHOD call rel_path[@offset]" for every session write the fake saw.
func (f *fixture) uploadLog() []string {
	var out []string
	for _, r := range f.srv.Requests() {
		_, call, ok := strings.Cut(r.Path, "/session/")
		if !ok || r.Method == "GET" {
			continue
		}
		var body struct {
			RelPath string `json:"rel_path"`
			Offset  *int64 `json:"offset"`
		}
		_ = json.Unmarshal(r.Body, &body)
		entry := call + " " + body.RelPath
		if body.Offset != nil {
			entry += fmt.Sprintf("@%d", *body.Offset)
		}
		out = append(out, entry)
	}
	return out
}

func (f *fixture) upload(t *testing.T, dir string, since time.Time) (UploadResult, error) {
	t.Helper()
	return Upload(f.ctx, &f.store, threadID, testSID, dir, since, nil)
}

func TestFindSessionFilePrefersCwdFolder(t *testing.T) {
	cfg := t.TempDir()
	want := filepath.Join(cfg, "projects", "-work-app", testSID+".jsonl")
	writeFile(t, want, "x\n")
	writeFile(t, filepath.Join(cfg, "projects", "-other", testSID+".jsonl"), "y\n")
	got, err := FindSessionFile(cfg, "/work/app", testSID)
	if err != nil || got != want {
		t.Fatalf("got %q %v", got, err)
	}
}

func TestFindFallsBackToGlob(t *testing.T) {
	cfg := t.TempDir()
	want := filepath.Join(cfg, "projects", "-elsewhere", testSID+".jsonl")
	writeFile(t, want, "y\n")
	got, err := FindSessionFile(cfg, "/work/app", testSID)
	if err != nil || got != want {
		t.Fatalf("got %q %v", got, err)
	}
	if _, err := FindSessionFile(cfg, "/work/app", "../../etc"); err == nil {
		t.Fatal("non-uuid id accepted")
	}
	if _, err := FindSessionFile(t.TempDir(), "/x", testSID); err == nil {
		t.Fatal("missing file found")
	}
}

func TestClaudeConfigDir(t *testing.T) {
	env := func(k string) string {
		if k == "CLAUDE_CONFIG_DIR" {
			return "/custom"
		}
		return ""
	}
	if got := ClaudeConfigDir(env, "/home/me"); got != "/custom" {
		t.Fatal(got)
	}
	if got := ClaudeConfigDir(func(string) string { return "" }, "/home/me"); got != "/home/me/.claude" {
		t.Fatal(got)
	}
}

func TestRelPathValidation(t *testing.T) {
	good := []string{
		testSID + ".jsonl",
		testSID + "/subagents/agent-1.jsonl",
		testSID + "/subagents/agent-1.meta.json",
		testSID + "/tool-results/a/b.txt",
		"memory/MEMORY.md",
	}
	bad := []string{
		"", "../x", "/etc/passwd", "memory/../../x", "memory/", "memory/a/b.md", "memory", `a\b`, "x\x00y",
		"notauuid.jsonl", testSID + "/other/x", testSID + "/subagents/x.txt", testSID + "/tool-results",
		testSID + "/tool-results/../../x", testSID + "/subagents/../x.jsonl", "./" + testSID + ".jsonl",
	}
	for _, rel := range good {
		if err := ValidateRelPath(rel); err != nil {
			t.Errorf("%q rejected: %v", rel, err)
		}
	}
	for _, rel := range bad {
		if err := ValidateRelPath(rel); err == nil {
			t.Errorf("%q accepted", rel)
		}
	}
}

func projectTree(t *testing.T) string {
	dir := t.TempDir()
	writeFile(t, filepath.Join(dir, testSID+".jsonl"), "{\"n\":1}\n{\"n\":2}\n")
	writeFile(t, filepath.Join(dir, testSID, "subagents", "a.jsonl"), "{\"s\":1}\n")
	writeFile(t, filepath.Join(dir, testSID, "subagents", "a.meta.json"), "{}")
	writeFile(t, filepath.Join(dir, testSID, "tool-results", "r.txt"), "result")
	writeFile(t, filepath.Join(dir, "memory", "MEMORY.md"), "memo")
	return dir
}

func TestUploadOrderIsJSONLThenSubagentsThenToolResultsThenMemory(t *testing.T) {
	f := newFixture(t)
	dir := projectTree(t)
	res, err := f.upload(t, dir, time.Now().Add(-time.Hour))
	if err != nil {
		t.Fatal(err)
	}
	var writes []string
	for _, e := range f.uploadLog() {
		if !strings.HasPrefix(e, "checkpoint") {
			writes = append(writes, e)
		}
	}
	want := []string{
		"append " + testSID + ".jsonl@0",
		"append " + testSID + "/subagents/a.jsonl@0",
		"file " + testSID + "/subagents/a.meta.json",
		"file " + testSID + "/tool-results/r.txt",
		"file memory/MEMORY.md",
	}
	if strings.Join(writes, "|") != strings.Join(want, "|") {
		t.Fatalf("order:\n%v\nwant\n%v", writes, want)
	}
	if res.Files != 5 {
		t.Fatalf("files %d", res.Files)
	}
	if got := f.srv.SessionFile(threadID, "_memory", "memory/MEMORY.md"); string(got) != "memo" {
		t.Fatalf("memory stored under %q", got)
	}
}

func TestRepeatedUploadSendsOnlyNewBytes(t *testing.T) {
	f := newFixture(t)
	dir := projectTree(t)
	main := filepath.Join(dir, testSID+".jsonl")
	if _, err := f.upload(t, dir, time.Now().Add(time.Hour)); err != nil {
		t.Fatal(err)
	}
	before := len(f.uploadLog())
	fh, _ := os.OpenFile(main, os.O_APPEND|os.O_WRONLY, 0)
	fh.WriteString("{\"n\":3}\n")
	fh.Close()
	if _, err := f.upload(t, dir, time.Now().Add(time.Hour)); err != nil {
		t.Fatal(err)
	}
	var appends []string
	for _, e := range f.uploadLog()[before:] {
		if strings.HasPrefix(e, "append") {
			appends = append(appends, e)
		}
	}
	if strings.Join(appends, "|") != "append "+testSID+".jsonl@16" {
		t.Fatalf("appends %v", appends)
	}
}

func TestUnterminatedTailIsResentWithNextChunk(t *testing.T) {
	f := newFixture(t)
	dir := t.TempDir()
	main := filepath.Join(dir, testSID+".jsonl")
	writeFile(t, main, "a\nb")
	res, err := f.upload(t, dir, time.Now())
	if err != nil || res.Bytes != 2 {
		t.Fatalf("%v %+v", err, res)
	}
	writeFile(t, main, "a\nb\nc\n")
	if _, err := f.upload(t, dir, time.Now()); err != nil {
		t.Fatal(err)
	}
	if got := string(f.srv.SessionFile(threadID, testSID, testSID+".jsonl")); got != "a\nb\nc\n" {
		t.Fatalf("stored %q", got)
	}
}

func TestLostResponseRetryIsSafe(t *testing.T) {
	f := newFixture(t)
	dir := t.TempDir()
	writeFile(t, filepath.Join(dir, testSID+".jsonl"), "a\nb\n")
	f.srv.DropResponseOnce()
	if _, err := f.upload(t, dir, time.Now()); err != nil {
		t.Fatal(err)
	}
	if got := string(f.srv.SessionFile(threadID, testSID, testSID+".jsonl")); got != "a\nb\n" {
		t.Fatalf("stored %q", got)
	}
}

func TestGapResumes(t *testing.T) {
	f := newFixture(t)
	dir := t.TempDir()
	rel := testSID + ".jsonl"
	writeFile(t, filepath.Join(dir, rel), "a\nb\nc\n")
	f.srv.SeedSessionFile(threadID, testSID, rel, []byte("a\nb\n"))
	// Yak loses its copy between the length lookup and the first append, so the offset is past the stored length.
	once := false
	f.srv.OnRequest = func(r *http.Request) {
		if r.Method == "POST" && strings.HasSuffix(r.URL.Path, "/append") && !once {
			once = true
			f.srv.SeedSessionFile(threadID, testSID, rel, nil)
		}
	}
	if _, err := f.upload(t, dir, time.Now()); err != nil {
		t.Fatal(err)
	}
	if got := string(f.srv.SessionFile(threadID, testSID, rel)); got != "a\nb\nc\n" {
		t.Fatalf("stored %q", got)
	}
}

func TestDivergedStopsAndNothingElseSent(t *testing.T) {
	f := newFixture(t)
	dir := projectTree(t)
	f.srv.SeedSessionFile(threadID, testSID, testSID+".jsonl", []byte("{\"n\":9}\n"))
	_, err := f.upload(t, dir, time.Now().Add(-time.Hour))
	if !errors.Is(err, ErrDiverged) {
		t.Fatalf("got %v", err)
	}
	for _, e := range f.uploadLog() {
		if strings.HasPrefix(e, "file") || strings.Contains(e, "subagents") {
			t.Fatalf("sent after divergence: %s", e)
		}
	}
	if got := string(f.srv.SessionFile(threadID, testSID, testSID+".jsonl")); got != "{\"n\":9}\n" {
		t.Fatalf("store overwritten: %q", got)
	}
}

func TestCheckpointMismatchFails(t *testing.T) {
	f := newFixture(t)
	dir := t.TempDir()
	writeFile(t, filepath.Join(dir, testSID+".jsonl"), "a\nb\n")
	// Same length and same first bytes at the tail position but different content before it.
	f.srv.SeedSessionFile(threadID, testSID, testSID+".jsonl", []byte("a\nX\n"))
	_, err := f.upload(t, dir, time.Now())
	if !errors.Is(err, ErrCheckpointMismatch) {
		t.Fatalf("got %v", err)
	}
}

func TestOnlyChangedMemoryFilesUploaded(t *testing.T) {
	f := newFixture(t)
	dir := t.TempDir()
	writeFile(t, filepath.Join(dir, testSID+".jsonl"), "a\n")
	old := filepath.Join(dir, "memory", "old.md")
	writeFile(t, old, "old")
	writeFile(t, filepath.Join(dir, "memory", "new.md"), "new")
	past := time.Now().Add(-48 * time.Hour)
	os.Chtimes(old, past, past)
	if _, err := f.upload(t, dir, time.Now().Add(-time.Hour)); err != nil {
		t.Fatal(err)
	}
	if f.srv.SessionFile(threadID, "_memory", "memory/old.md") != nil && len(f.srv.SessionFile(threadID, "_memory", "memory/old.md")) > 0 {
		t.Fatal("old memory uploaded")
	}
	if string(f.srv.SessionFile(threadID, "_memory", "memory/new.md")) != "new" {
		t.Fatal("new memory missing")
	}
}

func TestSymlinksAreSkipped(t *testing.T) {
	f := newFixture(t)
	dir := t.TempDir()
	writeFile(t, filepath.Join(dir, testSID+".jsonl"), "a\n")
	secret := filepath.Join(t.TempDir(), "secret.txt")
	writeFile(t, secret, "secret")
	os.MkdirAll(filepath.Join(dir, testSID, "tool-results"), 0o755)
	if err := os.Symlink(secret, filepath.Join(dir, testSID, "tool-results", "link.txt")); err != nil {
		t.Skip(err)
	}
	if _, err := f.upload(t, dir, time.Now()); err != nil {
		t.Fatal(err)
	}
	if len(f.srv.SessionFile(threadID, testSID, testSID+"/tool-results/link.txt")) != 0 {
		t.Fatal("symlink uploaded")
	}
}

func TestLargeSessionMultiChunk(t *testing.T) {
	f := newFixture(t)
	dir := t.TempDir()
	line := strings.Repeat("x", 998) + "\n"
	big := strings.Repeat(line, 3*1024*1024/len(line)+1)
	writeFile(t, filepath.Join(dir, testSID+".jsonl"), big)
	if _, err := f.upload(t, dir, time.Now()); err != nil {
		t.Fatal(err)
	}
	appends := 0
	for _, e := range f.uploadLog() {
		if strings.HasPrefix(e, "append") {
			appends++
		}
	}
	if appends < 3 || appends > 6 {
		t.Fatalf("appends %d", appends)
	}
	if !bytes.Equal(f.srv.SessionFile(threadID, testSID, testSID+".jsonl"), []byte(big)) {
		t.Fatal("stored content differs")
	}
}
