package main

import (
	"encoding/json"
	"io"
	"log/slog"
	"net/http"
	"sort"
	"strings"
	"sync"
)

// bodySampleBytes is how much of a non-200 body is kept to work out its shape.
const bodySampleBytes = 4 << 10

// loggedHeaderValues are the only response headers whose values are logged.
var loggedHeaderValues = map[string]bool{
	"Content-Type":   true,
	"Retry-After":    true,
	"Request-Id":     true,
	"Content-Length": true,
}

func loggableHeaderValue(name string) bool {
	return loggedHeaderValues[name] || strings.HasPrefix(name, "Anthropic-Ratelimit-")
}

// logNonOKResponse wraps response.Body so the first bytes are sampled as they
// stream to the client, and logs status, headers and body shape once the body
// ends, fails or is closed. Body values are never logged, only key names, the
// string value of error.type and the length.
func logNonOKResponse(logger *slog.Logger, response *http.Response) {
	tee := &shapeTee{body: response.Body}
	names := make([]string, 0, len(response.Header))
	for name := range response.Header {
		entry := name
		if loggableHeaderValue(name) {
			entry = name + "=" + strings.Join(response.Header[name], ",")
		}
		names = append(names, entry)
	}
	sort.Strings(names)
	status := response.StatusCode
	tee.finish = func(sample []byte, total int64) {
		logger.Warn("upstream non-200", "status", status, "headers", names, "body_shape", bodyShape(sample, total), "body_bytes", total)
	}
	response.Body = tee
}

type shapeTee struct {
	body   io.ReadCloser
	sample []byte
	total  int64
	once   sync.Once
	finish func(sample []byte, total int64)
}

func (t *shapeTee) Read(p []byte) (int, error) {
	n, err := t.body.Read(p)
	if room := bodySampleBytes - len(t.sample); room > 0 && n > 0 {
		t.sample = append(t.sample, p[:min(n, room)]...)
	}
	t.total += int64(n)
	if err != nil {
		t.log()
	}
	return n, err
}

func (t *shapeTee) Close() error {
	err := t.body.Close()
	t.log()
	return err
}

func (t *shapeTee) log() { t.once.Do(func() { t.finish(t.sample, t.total) }) }

// bodyShape describes a body without its values.
func bodyShape(sample []byte, total int64) string {
	var decoded any
	if total > int64(len(sample)) || json.Unmarshal(sample, &decoded) != nil {
		return "non-json"
	}
	var paths []string
	collectPaths("", decoded, &paths)
	sort.Strings(paths)
	shape := "json keys=" + strings.Join(paths, ",")
	if object, ok := decoded.(map[string]any); ok {
		if inner, ok := object["error"].(map[string]any); ok {
			if kind, ok := inner["type"].(string); ok {
				shape += " error.type=" + kind
			}
		}
	}
	return shape
}

func collectPaths(prefix string, value any, paths *[]string) {
	switch typed := value.(type) {
	case map[string]any:
		for key, inner := range typed {
			path := key
			if prefix != "" {
				path = prefix + "." + key
			}
			*paths = append(*paths, path)
			collectPaths(path, inner, paths)
		}
	case []any:
		for _, inner := range typed {
			collectPaths(prefix+"[]", inner, paths)
		}
	}
}
