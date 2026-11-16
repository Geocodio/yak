package slash

import (
	"reflect"
	"testing"
)

func TestTeleportArgs(t *testing.T) {
	cases := []struct {
		raw  string
		want []string
	}{
		{"", []string{}},
		{"teleport", []string{}},
		{"teleport fix the cache", []string{"--message", "fix the cache"}},
		{`teleport "fix x"`, []string{"--message", "fix x"}},
		{"teleport 42", []string{"42"}},
		{"teleport 42 --keep --stash", []string{"42", "--keep", "--stash"}},
		{"teleport --stash", []string{"--stash"}},
		{"teleport fix; rm -rf ~ $(x)", []string{"--message", "fix; rm -rf ~ $(x)"}},
		{"teleport don't break", []string{"--message", "don't break"}},
		{`teleport 42 --add "a b"`, []string{"42", "--add", "a b"}},
	}
	for _, c := range cases {
		got, err := TeleportArgs(c.raw)
		if err != nil || !reflect.DeepEqual(got, c.want) {
			t.Errorf("%q: got %q, %v; want %q", c.raw, got, err, c.want)
		}
	}
}

func TestTeleportArgsErrors(t *testing.T) {
	for _, raw := range []string{`teleport "fix x`, `teleport 42 --add "a`, "status", "teleporter"} {
		if got, err := TeleportArgs(raw); err == nil {
			t.Errorf("%q: want error, got %q", raw, got)
		}
	}
}
