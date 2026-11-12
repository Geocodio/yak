package plugincheck

import (
	"encoding/json"
	"os"
	"path/filepath"
	"regexp"
	"strings"
	"testing"

	"github.com/geocodio/yak/cli/internal/version"
)

const pluginDir = "../../../plugin"

func readJSON(t *testing.T, rel string, into any) {
	t.Helper()
	b, err := os.ReadFile(filepath.Join(pluginDir, rel))
	if err != nil {
		t.Fatal(err)
	}
	if err := json.Unmarshal(b, into); err != nil {
		t.Fatalf("%s: %v", rel, err)
	}
}

func TestPluginFilesAreValidAndConsistent(t *testing.T) {
	var manifest struct{ Name, Version string }
	readJSON(t, ".claude-plugin/plugin.json", &manifest)
	if manifest.Name != "yak" || manifest.Version != version.Version {
		t.Fatalf("manifest %+v, want yak %s", manifest, version.Version)
	}

	var hooks struct {
		Hooks map[string][]struct {
			Matcher string
			Hooks   []struct {
				Command string
				Timeout int
			}
		}
	}
	readJSON(t, "hooks/hooks.json", &hooks)
	want := map[string]string{"SessionStart": "hook session-start", "UserPromptSubmit": "hook user-prompt-submit"}
	// Teleport runs inside the UserPromptSubmit hook, so it gets the long timeout.
	timeouts := map[string]int{"SessionStart": 5, "UserPromptSubmit": 900}
	if len(hooks.Hooks) != len(want) {
		t.Fatalf("hook events %v", hooks.Hooks)
	}
	for event, suffix := range want {
		groups := hooks.Hooks[event]
		if len(groups) != 1 || len(groups[0].Hooks) != 1 {
			t.Fatalf("%s: %+v", event, groups)
		}
		h := groups[0].Hooks[0]
		if h.Command != `"${CLAUDE_PLUGIN_ROOT}/bin/yak" `+suffix || h.Timeout != timeouts[event] {
			t.Errorf("%s: %+v", event, h)
		}
	}
	if hooks.Hooks["SessionStart"][0].Matcher != "resume" {
		t.Error("SessionStart matcher must be resume")
	}

	var mcp struct {
		McpServers map[string]struct {
			Command string
			Args    []string
		}
	}
	readJSON(t, ".mcp.json", &mcp)
	srv := mcp.McpServers["yak"]
	if srv.Command != "${CLAUDE_PLUGIN_ROOT}/bin/yak" || len(srv.Args) != 1 || srv.Args[0] != "mcp" {
		t.Fatalf("mcp %+v", srv)
	}

	cmdMd, err := os.ReadFile(filepath.Join(pluginDir, "commands/yak.md"))
	if err != nil {
		t.Fatal(err)
	}
	if !strings.Contains(string(cmdMd), "description: Teleport this session to Yak") ||
		!strings.Contains(string(cmdMd), `"${CLAUDE_PLUGIN_ROOT}/bin/yak" slash-teleport`) {
		t.Errorf("command file: %s", cmdMd)
	}

	banned := regexp.MustCompile("(?i)lap" + "top")
	err = filepath.Walk(pluginDir, func(path string, info os.FileInfo, err error) error {
		if err != nil || info.IsDir() {
			return err
		}
		b, _ := os.ReadFile(path)
		if strings.Contains(string(b), "\u2014") || banned.Match(b) {
			t.Errorf("%s contains an em dash or the word laptop", path)
		}
		return nil
	})
	if err != nil {
		t.Fatal(err)
	}
}
