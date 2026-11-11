package mcpserver

import (
	"bytes"
	"encoding/json"
	"strings"
	"testing"
)

func run(t *testing.T, input string) []map[string]any {
	t.Helper()
	var out bytes.Buffer
	if err := Serve(strings.NewReader(input), &out); err != nil {
		t.Fatal(err)
	}
	var replies []map[string]any
	for _, l := range strings.Split(strings.TrimSpace(out.String()), "\n") {
		if l == "" {
			continue
		}
		var m map[string]any
		if err := json.Unmarshal([]byte(l), &m); err != nil {
			t.Fatalf("%q: %v", l, err)
		}
		replies = append(replies, m)
	}
	return replies
}

func TestInitializeHandshake(t *testing.T) {
	r := run(t, `{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26"}}`+"\n"+
		`{"jsonrpc":"2.0","method":"notifications/initialized"}`+"\n"+`{"jsonrpc":"2.0","id":2,"method":"ping"}`+"\n")
	if len(r) != 2 {
		t.Fatalf("%d replies", len(r))
	}
	res := r[0]["result"].(map[string]any)
	if res["protocolVersion"] != "2025-03-26" || res["serverInfo"].(map[string]any)["name"] != "yak" {
		t.Fatal(res)
	}
	if _, ok := res["capabilities"].(map[string]any)["tools"]; !ok {
		t.Fatal("no tools capability")
	}
}

func TestToolsListIsExactlySpawnThread(t *testing.T) {
	r := run(t, `{"jsonrpc":"2.0","id":1,"method":"tools/list"}`+"\n")
	tools := r[0]["result"].(map[string]any)["tools"].([]any)
	if len(tools) != 1 || tools[0].(map[string]any)["name"] != "spawn_thread" {
		t.Fatal(tools)
	}
}

func TestSendMessageAndListThreadsAbsent(t *testing.T) {
	r := run(t, `{"jsonrpc":"2.0","id":1,"method":"tools/list"}`+"\n")
	b, _ := json.Marshal(r[0])
	for _, bad := range []string{"send_message", "list_threads"} {
		if strings.Contains(string(b), bad) {
			t.Fatal(bad)
		}
	}
}

func TestSpawnThreadRefusal(t *testing.T) {
	r := run(t, `{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"spawn_thread","arguments":{"title":"x","prompt":"y","kind":"research"}}}`+"\n")
	res := r[0]["result"].(map[string]any)
	text := res["content"].([]any)[0].(map[string]any)["text"]
	if res["isError"] != true || text != "Teleport this session to Yak first. Sub-threads run on Yak. Run /yak teleport, then ask again." {
		t.Fatal(res)
	}
}

func TestOtherToolIsMethodNotFound(t *testing.T) {
	r := run(t, `{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"send_message"}}`+"\n"+`{"jsonrpc":"2.0","id":2,"method":"nope"}`+"\n")
	for _, m := range r {
		if m["error"].(map[string]any)["code"] != float64(-32601) {
			t.Fatal(m)
		}
	}
}

func TestMalformedJSONReturnsParseError(t *testing.T) {
	r := run(t, "{not json\n"+`{"jsonrpc":"2.0","id":1,"method":"ping"}`+"\n")
	if len(r) != 2 || r[0]["error"].(map[string]any)["code"] != float64(-32700) || r[1]["result"] == nil {
		t.Fatal(r)
	}
}

func TestOversizedLineDoesNotCrash(t *testing.T) {
	big := `{"jsonrpc":"2.0","id":1,"method":"ping","params":"` + strings.Repeat("a", MaxLineBytes+100) + `"}` + "\n"
	r := run(t, big+`{"jsonrpc":"2.0","id":2,"method":"ping"}`+"\n")
	if len(r) != 2 || r[0]["error"] == nil || r[1]["id"] != float64(2) || r[1]["result"] == nil {
		t.Fatalf("%v", r)
	}
}
