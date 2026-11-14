package session

import (
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/geocodio/yak/cli/internal/fakeyak"
)

var downloadNow = time.Date(2026, 11, 11, 9, 0, 0, 0, time.UTC)

// seed stores content on the fake and returns its manifest entry.
func (f *fixture) seed(rel, content string) ManifestFile {
	session := testSID
	if strings.HasPrefix(rel, "memory/") {
		session = "_memory"
	}
	f.srv.SeedSessionFile(threadID, session, rel, []byte(content))
	return ManifestFile{RelPath: rel, Length: int64(len(content)), SHA256: hexSum([]byte(content))}
}

func (f *fixture) download(t *testing.T, dir string, files, lastUp []ManifestFile, memory []MemoryManifestFile) (DownloadResult, error) {
	t.Helper()
	return f.downloadWithBaseline(t, dir, files, lastUp, memory, nil)
}

func (f *fixture) downloadWithBaseline(t *testing.T, dir string, files, lastUp []ManifestFile, memory []MemoryManifestFile, baseline map[string]string) (DownloadResult, error) {
	t.Helper()
	f.srv.OpenTeleport(threadID, 1, "down", "ready")
	return Download(f.ctx, &f.store, threadID, DownloadPlan{
		SessionID: testSID, ProjectDir: dir, Files: files, LastUp: lastUp, Memory: memory, MemoryBaseline: baseline, Now: downloadNow,
	})
}

func readFileString(t *testing.T, path string) string {
	t.Helper()
	b, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}
	return string(b)
}

func noTempFiles(t *testing.T, dir string) {
	t.Helper()
	filepath.WalkDir(dir, func(p string, d os.DirEntry, _ error) error {
		if d != nil && strings.Contains(d.Name(), ".yak-tmp-") {
			t.Errorf("temp file left: %s", p)
		}
		return nil
	})
}

func newDownloadFixture(t *testing.T) *fixture {
	t.Helper()
	f := newFixture(t)
	// The fixture's first device gets id 1 from the fake; the down teleport belongs to it.
	return f
}

func TestDownloadVerifiesBeforeAnyRename(t *testing.T) {
	f := newDownloadFixture(t)
	dir := t.TempDir()
	main := filepath.Join(dir, testSID+".jsonl")
	writeFile(t, main, "local\n")
	good := f.seed(testSID+".jsonl", "yak\n")
	bad := f.seed(testSID+"/tool-results/a.txt", "payload")
	bad.SHA256 = hexSum([]byte("something else"))
	_, err := f.download(t, dir, []ManifestFile{good, bad}, nil, nil)
	if err == nil {
		t.Fatal("corrupt file accepted")
	}
	if readFileString(t, main) != "local\n" {
		t.Fatal("target touched")
	}
	if _, statErr := os.Stat(filepath.Join(dir, testSID, "tool-results", "a.txt")); statErr == nil {
		t.Fatal("corrupt file installed")
	}
	noTempFiles(t, dir)
}

func TestHostileManifestPathsRejectedBeforeAnyRead(t *testing.T) {
	for _, rel := range []string{"../evil", "/abs", "memory/../../x", testSID + "/../../x", "memory/a/b.md", testSID + "/tool-results/../../x"} {
		f := newDownloadFixture(t)
		dir := t.TempDir()
		good := f.seed(testSID+".jsonl", "yak\n")
		hostile := ManifestFile{RelPath: rel, Length: 3, SHA256: hexSum([]byte("bad"))}
		if _, err := f.download(t, dir, []ManifestFile{good, hostile}, nil, nil); err == nil {
			t.Errorf("%q accepted", rel)
		}
		for _, r := range f.srv.Requests() {
			if strings.HasSuffix(r.Path, "/read") {
				t.Errorf("%q: read happened before validation", rel)
			}
		}
		if _, err := os.Stat(filepath.Join(dir, testSID+".jsonl")); err == nil {
			t.Errorf("%q: partial install", rel)
		}
	}
}

func TestOtherSessionPathsRejected(t *testing.T) {
	f := newDownloadFixture(t)
	other := "11111111-2222-4333-8444-555555555555"
	entry := ManifestFile{RelPath: other + ".jsonl", Length: 1, SHA256: hexSum([]byte("x"))}
	if _, err := f.download(t, t.TempDir(), []ManifestFile{entry}, nil, nil); err == nil {
		t.Fatal("foreign session path accepted")
	}
}

func TestUnchangedLocalSessionReplacedWithoutBackup(t *testing.T) {
	f := newDownloadFixture(t)
	dir := t.TempDir()
	main := filepath.Join(dir, testSID+".jsonl")
	writeFile(t, main, "old\n")
	lastUp := ManifestFile{RelPath: testSID + ".jsonl", Length: 4, SHA256: hexSum([]byte("old\n"))}
	res, err := f.download(t, dir, []ManifestFile{f.seed(testSID+".jsonl", "old\nnew\n")}, []ManifestFile{lastUp}, nil)
	if err != nil || res.LocalBackup != "" {
		t.Fatalf("%v %+v", err, res)
	}
	if readFileString(t, main) != "old\nnew\n" {
		t.Fatal("not replaced")
	}
	noTempFiles(t, dir)
}

func TestDivergedLocalSessionBackedUp(t *testing.T) {
	f := newDownloadFixture(t)
	dir := t.TempDir()
	main := filepath.Join(dir, testSID+".jsonl")
	writeFile(t, main, "mine\n")
	lastUp := ManifestFile{RelPath: testSID + ".jsonl", SHA256: hexSum([]byte("old\n"))}
	res, err := f.download(t, dir, []ManifestFile{f.seed(testSID+".jsonl", "yak\n")}, []ManifestFile{lastUp}, nil)
	if err != nil {
		t.Fatal(err)
	}
	want := filepath.Join(dir, testSID+".local-2026-11-11.jsonl")
	if res.LocalBackup != want || readFileString(t, want) != "mine\n" || readFileString(t, main) != "yak\n" {
		t.Fatalf("%+v", res)
	}
}

func TestBackupNameCollisionGetsSuffix(t *testing.T) {
	f := newDownloadFixture(t)
	dir := t.TempDir()
	writeFile(t, filepath.Join(dir, testSID+".jsonl"), "mine\n")
	writeFile(t, filepath.Join(dir, testSID+".local-2026-11-11.jsonl"), "earlier\n")
	res, err := f.download(t, dir, []ManifestFile{f.seed(testSID+".jsonl", "yak\n")}, nil, nil)
	if err != nil {
		t.Fatal(err)
	}
	want := filepath.Join(dir, testSID+".local-2026-11-11-2.jsonl")
	if res.LocalBackup != want || readFileString(t, want) != "mine\n" {
		t.Fatalf("%+v", res)
	}
	if readFileString(t, filepath.Join(dir, testSID+".local-2026-11-11.jsonl")) != "earlier\n" {
		t.Fatal("earlier backup overwritten")
	}
}

func memEntry(f *fixture, name, content string) MemoryManifestFile {
	m := f.seed("memory/"+name, content)
	return MemoryManifestFile{RelPath: m.RelPath, Length: m.Length, SHA256: m.SHA256, ChangedSinceLastTeleport: true}
}

func TestMemoryEditedLocallyKeepsLocalAndWritesYakCopy(t *testing.T) {
	f := newDownloadFixture(t)
	dir := t.TempDir()
	writeFile(t, filepath.Join(dir, "memory", "MEMORY.md"), "my edit")
	baseline := map[string]string{"memory/MEMORY.md": hexSum([]byte("base"))}
	res, err := f.downloadWithBaseline(t, dir, nil, nil, []MemoryManifestFile{memEntry(f, "MEMORY.md", "yak edit")}, baseline)
	if err != nil {
		t.Fatal(err)
	}
	copyPath := filepath.Join(dir, "memory", "MEMORY.yak-2026-11-11.md")
	if len(res.MemoryConflicts) != 1 || res.MemoryConflicts[0] != copyPath {
		t.Fatalf("%+v", res)
	}
	if readFileString(t, copyPath) != "yak edit" || readFileString(t, filepath.Join(dir, "memory", "MEMORY.md")) != "my edit" {
		t.Fatal("contents wrong")
	}
}

func TestMemoryWithNoBaselineIsConflict(t *testing.T) {
	f := newDownloadFixture(t)
	dir := t.TempDir()
	writeFile(t, filepath.Join(dir, "memory", "notes.txt"), "mine")
	res, err := f.download(t, dir, nil, nil, []MemoryManifestFile{memEntry(f, "notes.txt", "yak")})
	if err != nil || len(res.MemoryConflicts) != 1 || !strings.HasSuffix(res.MemoryConflicts[0], "notes.yak-2026-11-11.txt") {
		t.Fatalf("%v %+v", err, res)
	}
}

func TestMemoryUnchangedLocallyIsReplacedAndBackedUp(t *testing.T) {
	f := newDownloadFixture(t)
	dir := t.TempDir()
	target := filepath.Join(dir, "memory", "MEMORY.md")
	writeFile(t, target, "base")
	baseline := map[string]string{"memory/MEMORY.md": hexSum([]byte("base"))}
	res, err := f.downloadWithBaseline(t, dir, nil, nil, []MemoryManifestFile{memEntry(f, "MEMORY.md", "yak edit")}, baseline)
	if err != nil {
		t.Fatal(err)
	}
	backup := filepath.Join(dir, "memory", "MEMORY.local-2026-11-11.md")
	if len(res.MemoryBackups) != 1 || res.MemoryBackups[0] != backup || readFileString(t, backup) != "base" || readFileString(t, target) != "yak edit" {
		t.Fatalf("%+v", res)
	}
}

func TestMemoryMissingLocalWritten(t *testing.T) {
	f := newDownloadFixture(t)
	dir := t.TempDir()
	res, err := f.download(t, dir, nil, nil, []MemoryManifestFile{memEntry(f, "MEMORY.md", "yak")})
	if err != nil || readFileString(t, filepath.Join(dir, "memory", "MEMORY.md")) != "yak" || len(res.MemoryConflicts)+len(res.MemoryBackups) != 0 {
		t.Fatalf("%v %+v", err, res)
	}
	if len(res.Written) != 1 || res.Written[0].SessionID != "_memory" {
		t.Fatalf("%+v", res.Written)
	}
}

func TestResumeOffsetDownload(t *testing.T) {
	f := newDownloadFixture(t)
	f.srv.ReadLimit = 5
	dir := t.TempDir()
	content := "0123456789abcdefghij\n"
	if _, err := f.download(t, dir, []ManifestFile{f.seed(testSID+".jsonl", content)}, nil, nil); err != nil {
		t.Fatal(err)
	}
	if readFileString(t, filepath.Join(dir, testSID+".jsonl")) != content {
		t.Fatal("content differs")
	}
}

func TestShortReadFails(t *testing.T) {
	f := newDownloadFixture(t)
	dir := t.TempDir()
	entry := f.seed(testSID+".jsonl", "short\n")
	entry.Length = 100
	if _, err := f.download(t, dir, []ManifestFile{entry}, nil, nil); err == nil || !strings.Contains(err.Error(), "short read") {
		t.Fatalf("got %v", err)
	}
	noTempFiles(t, dir)
}

func TestLargeFileIsFetchedInRanges(t *testing.T) {
	f := newDownloadFixture(t)
	dir := t.TempDir()
	content := strings.Repeat("0123456789abcdef", 40000) + "\n" // about 640 KB, three ranges
	entry := f.seed(testSID+".jsonl", content)
	if _, err := f.download(t, dir, []ManifestFile{entry}, nil, nil); err != nil {
		t.Fatal(err)
	}
	if readFileString(t, filepath.Join(dir, testSID+".jsonl")) != content {
		t.Fatal("content differs")
	}
	var offsets []string
	for _, r := range f.srv.Requests() {
		if strings.HasSuffix(r.Path, "/read") {
			q, _ := url.ParseQuery(r.Query)
			if q.Get("limit") != "262144" {
				t.Fatalf("limit %q", q.Get("limit"))
			}
			offsets = append(offsets, q.Get("offset"))
		}
	}
	if strings.Join(offsets, ",") != ",262144,524288" {
		t.Fatalf("offsets %v", offsets)
	}
}

func TestDroppedRangeIsRetriedFromTheSameOffset(t *testing.T) {
	f := newDownloadFixture(t)
	dir := t.TempDir()
	content := strings.Repeat("0123456789abcdef", 40000) + "\n"
	entry := f.seed(testSID+".jsonl", content)
	// Let the first range through, then drop the second twice.
	var reads int
	f.srv.OnRequest = func(r *http.Request) {
		if strings.HasSuffix(r.URL.Path, "/read") {
			reads++
			if reads == 2 {
				f.srv.InjectFault(fakeyak.Fault{Match: "/read", Kind: fakeyak.FaultDrop, Times: 2})
			}
		}
	}
	if _, err := f.download(t, dir, []ManifestFile{entry}, nil, nil); err != nil {
		t.Fatal(err)
	}
	if readFileString(t, filepath.Join(dir, testSID+".jsonl")) != content {
		t.Fatal("content differs")
	}
	for _, r := range f.srv.Requests() {
		if strings.HasSuffix(r.Path, "/read") && r.Query == "" {
			t.Fatal("a range restarted at byte 0")
		}
	}
}
