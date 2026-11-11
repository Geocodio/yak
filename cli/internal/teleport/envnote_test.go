package teleport

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/geocodio/yak/cli/internal/gitx/testrepo"
)

func baseInput(localPath string) NoteInput {
	return NoteInput{
		SandboxOS: "linux", SandboxArch: "x86_64", LocalOS: "darwin", LocalArch: "arm64",
		Primary: CheckoutMapping{SandboxPath: "/workspace", LocalPath: localPath},
		GitEnv:  testrepo.Env,
	}
}

func TestNoteSectionsOmittedWhenEmpty(t *testing.T) {
	repo := testrepo.New(t)
	repo.Commit(map[string]string{"a.txt": "a"}, "init")
	note := BuildLocalNote(baseInput(repo.Dir))
	for _, want := range []string{
		"[Yak] This session moved from Yak to this computer.",
		"System. Linux x86_64 -> macOS arm64. Binaries built on Linux will not run here.",
		"Paths. /workspace is now " + repo.Dir + ". Treat /workspace in earlier messages as that path.",
	} {
		if !strings.Contains(note, want) {
			t.Errorf("missing %q in:\n%s", want, note)
		}
	}
	for _, unwanted := range []string{"Suggested commands", "Missing here", "Ask the person"} {
		if strings.Contains(note, unwanted) {
			t.Errorf("%q should be omitted:\n%s", unwanted, note)
		}
	}
}

func TestRefreshSuggestionFromChangedFiles(t *testing.T) {
	repo := testrepo.New(t)
	from := repo.Commit(map[string]string{"composer.lock": "1", "src/a.go": "a"}, "one")
	to := repo.Commit(map[string]string{"composer.lock": "2", "web/package-lock.json": "x"}, "two")
	in := baseInput(repo.Dir)
	in.FromSHA, in.ToSHA = from, to
	in.Refresh = []RefreshRule{
		{Glob: "composer.lock", Command: "composer install"},
		{Glob: "package-lock.json", Command: "npm ci"},
		{Glob: "go.sum", Command: "go mod download"},
	}
	note := BuildLocalNote(in)
	if !strings.Contains(note, "Suggested commands. composer.lock changed on Yak: composer install\n") {
		t.Errorf("composer rule missing:\n%s", note)
	}
	if !strings.Contains(note, "  web/package-lock.json changed on Yak: npm ci\n") {
		t.Errorf("nested match missing:\n%s", note)
	}
	if strings.Contains(note, "go mod download") {
		t.Errorf("unmatched rule suggested:\n%s", note)
	}
	in.FromSHA = ""
	if strings.Contains(BuildLocalNote(in), "Suggested commands") {
		t.Error("refresh without a base commit")
	}
}

func TestMissingFilesOnlyIgnoredAndAbsent(t *testing.T) {
	repo := testrepo.New(t)
	repo.Commit(map[string]string{".gitignore": ".env\nstorage/fixtures/\n*.log\n", "src/main.go": "x"}, "init")
	repo.Write("present.log", "here")
	in := baseInput(repo.Dir)
	in.TouchedPaths = []string{
		"/workspace/.env", // ignored, absent: listed
		"/workspace/storage/fixtures/webhooks.json", // ignored directory, absent: listed
		"/workspace/present.log",                    // ignored but present: not listed
		"/workspace/src/missing.go",                 // absent but not ignored: not listed
		"/workspace/../escape.log",                  // climbs out: refused
		"/etc/passwd",                               // outside the mapping
		"storage/fixtures/relative.json",            // relative to the checkout: listed
		"/workspace/.env",                           // repeat
	}
	note := BuildLocalNote(in)
	section := note[strings.Index(note, "Missing here."):]
	for _, want := range []string{"  .env\n", "  storage/fixtures/webhooks.json\n", "  storage/fixtures/relative.json\n", "Ask the person before creating them. Do not guess their contents."} {
		if !strings.Contains(section, want) {
			t.Errorf("missing %q:\n%s", want, section)
		}
	}
	for _, unwanted := range []string{"present.log", "missing.go", "escape", "passwd"} {
		if strings.Contains(section, unwanted) {
			t.Errorf("%q listed:\n%s", unwanted, section)
		}
	}
	if strings.Count(section, "  .env\n") != 1 {
		t.Errorf(".env listed twice:\n%s", section)
	}
}

func TestMissingFilesInExtraCheckout(t *testing.T) {
	primary := testrepo.New(t)
	primary.Commit(map[string]string{"a": "a"}, "init")
	extra := testrepo.New(t)
	extra.Commit(map[string]string{".gitignore": ".env\n"}, "init")
	in := baseInput(primary.Dir)
	in.Extras = []CheckoutMapping{{SandboxPath: "/extras/api", LocalPath: extra.Dir}}
	in.TouchedPaths = []string{"/extras/api/.env"}
	note := BuildLocalNote(in)
	if !strings.Contains(note, "  /extras/api is now "+extra.Dir+".\n") || !strings.Contains(note, "  "+filepath.Join(extra.Dir, ".env")+"\n") {
		t.Errorf("extras not mapped:\n%s", note)
	}
}

func TestTouchedPathsToleratesBadLines(t *testing.T) {
	path := filepath.Join(t.TempDir(), "s.jsonl")
	lines := []string{
		`{"type":"assistant","message":{"content":[{"type":"tool_use","name":"Read","input":{"file_path":"/workspace/.env"}},{"type":"text","text":"hi"}]}}`,
		`this is not json`,
		`{"type":"assistant","message":{"content":"plain string"}}`,
		`{"type":"assistant","message":{"content":[{"type":"tool_use","name":"Bash","input":{"command":"cat /workspace/secret"}}]}}`,
		`{"type":"assistant","message":{"content":[{"type":"tool_use","name":"Grep","input":{"pattern":"x","path":"/workspace/storage"}},{"type":"tool_use","name":"Edit","input":{"file_path":"/workspace/.env"}}]}}`,
		`{"truncated":`,
		`{"type":"assistant","message":{"content":[{"type":"tool_use","name":"Write","input":{"file_path":"/workspace/out/a.json"}}]}}`,
	}
	os.WriteFile(path, []byte(strings.Join(lines, "\n")), 0o600)
	got, err := TouchedPaths(path)
	if err != nil {
		t.Fatal(err)
	}
	want := "/workspace/.env|/workspace/storage|/workspace/out/a.json"
	if strings.Join(got, "|") != want {
		t.Fatalf("got %v", got)
	}
	if _, err := TouchedPaths(filepath.Join(t.TempDir(), "nope")); err == nil {
		t.Fatal("missing transcript is not an error")
	}
}

func TestNoteHasNoLaptopWord(t *testing.T) {
	repo := testrepo.New(t)
	from := repo.Commit(map[string]string{"composer.lock": "1", ".gitignore": ".env\n"}, "one")
	to := repo.Commit(map[string]string{"composer.lock": "2"}, "two")
	in := baseInput(repo.Dir)
	in.FromSHA, in.ToSHA = from, to
	in.Refresh = []RefreshRule{{Glob: "composer.lock", Command: "composer install"}}
	in.TouchedPaths = []string{"/workspace/.env"}
	// The temp directory name carries the test name, so it is removed before looking for words.
	note := strings.ToLower(strings.ReplaceAll(BuildLocalNote(in), repo.Dir, "<checkout>"))
	for _, banned := range []string{"laptop", "family", "\u2014"} {
		if strings.Contains(note, banned) {
			t.Errorf("note contains %q", banned)
		}
	}
	for _, section := range []string{"system.", "paths.", "suggested commands.", "missing here."} {
		if !strings.Contains(note, section) {
			t.Errorf("section %q missing", section)
		}
	}
}
