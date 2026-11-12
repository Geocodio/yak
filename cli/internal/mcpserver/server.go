// Package mcpserver is the local MCP server of the plugin. It exposes one tool, spawn_thread, which only
// refuses: sub-threads run on Yak, so a session on this computer has to be teleported first.
package mcpserver

import (
	"bufio"
	"encoding/json"
	"errors"
	"io"
)

// MaxLineBytes is the longest request line the server reads; longer lines are dropped.
const MaxLineBytes = 1 << 20

// RefusalText is what spawn_thread answers.
const RefusalText = "Teleport this session to Yak first. Sub-threads run on Yak. Run /yak teleport, then ask again."

type request struct {
	ID     json.RawMessage `json:"id"`
	Method string          `json:"method"`
	Params json.RawMessage `json:"params"`
}

type rpcError struct {
	Code    int    `json:"code"`
	Message string `json:"message"`
}

type response struct {
	JSONRPC string          `json:"jsonrpc"`
	ID      json.RawMessage `json:"id"`
	Result  any             `json:"result,omitempty"`
	Error   *rpcError       `json:"error,omitempty"`
}

var spawnThreadTool = map[string]any{
	"name":        "spawn_thread",
	"description": "Start a sub-thread on Yak. Sub-threads only run on Yak, so this session must be teleported first.",
	"inputSchema": map[string]any{
		"type": "object",
		"properties": map[string]any{
			"title":  map[string]any{"type": "string"},
			"prompt": map[string]any{"type": "string"},
			"kind":   map[string]any{"type": "string"},
		},
	},
}

// Serve reads newline-delimited JSON-RPC 2.0 from in and writes replies to out until in ends.
func Serve(in io.Reader, out io.Writer) error {
	r := bufio.NewReaderSize(in, 64*1024)
	enc := json.NewEncoder(out)
	for {
		line, tooLong, err := readLine(r)
		if len(line) > 0 || tooLong {
			if reply := handle(line, tooLong); reply != nil {
				if werr := enc.Encode(reply); werr != nil {
					return werr
				}
			}
		}
		if err != nil {
			if errors.Is(err, io.EOF) {
				return nil
			}
			return err
		}
	}
}

// readLine returns one line without its newline. A line over MaxLineBytes is consumed and reported as tooLong.
func readLine(r *bufio.Reader) (line []byte, tooLong bool, err error) {
	for {
		chunk, e := r.ReadSlice('\n')
		if !tooLong {
			if len(line)+len(chunk) > MaxLineBytes+1 {
				tooLong, line = true, nil
			} else {
				line = append(line, chunk...)
			}
		}
		if errors.Is(e, bufio.ErrBufferFull) {
			continue
		}
		for len(line) > 0 && (line[len(line)-1] == '\n' || line[len(line)-1] == '\r') {
			line = line[:len(line)-1]
		}
		return line, tooLong, e
	}
}

func fail(id json.RawMessage, code int, message string) *response {
	if len(id) == 0 {
		id = json.RawMessage("null")
	}
	return &response{JSONRPC: "2.0", ID: id, Error: &rpcError{code, message}}
}

func handle(line []byte, tooLong bool) *response {
	if tooLong {
		return fail(nil, -32600, "Request is too long.")
	}
	var req request
	if json.Unmarshal(line, &req) != nil {
		return fail(nil, -32700, "Parse error.")
	}
	isNotification := len(req.ID) == 0
	ok := func(result any) *response {
		if isNotification {
			return nil
		}
		return &response{JSONRPC: "2.0", ID: req.ID, Result: result}
	}
	switch req.Method {
	case "initialize":
		var p struct {
			ProtocolVersion string `json:"protocolVersion"`
		}
		_ = json.Unmarshal(req.Params, &p)
		return ok(map[string]any{
			"protocolVersion": p.ProtocolVersion,
			"capabilities":    map[string]any{"tools": map[string]any{}},
			"serverInfo":      map[string]any{"name": "yak", "version": "1"},
		})
	case "notifications/initialized":
		return nil
	case "ping":
		return ok(map[string]any{})
	case "tools/list":
		return ok(map[string]any{"tools": []any{spawnThreadTool}})
	case "tools/call":
		var p struct {
			Name string `json:"name"`
		}
		_ = json.Unmarshal(req.Params, &p)
		if p.Name != "spawn_thread" {
			return fail(req.ID, -32601, "Unknown tool.")
		}
		return ok(map[string]any{
			"content": []any{map[string]any{"type": "text", "text": RefusalText}},
			"isError": true,
		})
	}
	if isNotification {
		return nil
	}
	return fail(req.ID, -32601, "Method not found.")
}
