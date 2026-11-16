package keychain

import (
	"errors"
	"reflect"
	"strings"
	"testing"
)

type call struct {
	name  string
	stdin string
	args  []string
}

type recorder struct {
	calls  []call
	stdout string
	err    error
}

func (r *recorder) run(name, stdin string, args ...string) (string, error) {
	r.calls = append(r.calls, call{name, stdin, args})
	return r.stdout, r.err
}

func TestMacOSSetGetDelete(t *testing.T) {
	r := &recorder{stdout: "yakdev_abc\n"}
	s := NewMacOS(r.run)
	if err := s.Set("https://y.test", "yakdev_abc"); err != nil {
		t.Fatal(err)
	}
	tok, err := s.Get("https://y.test")
	if err != nil || tok != "yakdev_abc" {
		t.Fatalf("%q %v", tok, err)
	}
	if err := s.Delete("https://y.test"); err != nil {
		t.Fatal(err)
	}
	want := [][]string{
		{"add-generic-password", "-U", "-s", "yak-cli", "-a", "https://y.test", "-w", "yakdev_abc"},
		{"find-generic-password", "-s", "yak-cli", "-a", "https://y.test", "-w"},
		{"delete-generic-password", "-s", "yak-cli", "-a", "https://y.test"},
	}
	for i, w := range want {
		if r.calls[i].name != "security" || !reflect.DeepEqual(r.calls[i].args, w) {
			t.Fatalf("call %d: %+v", i, r.calls[i])
		}
	}
}

func TestLinuxSetPutsTokenOnStdinNotArgv(t *testing.T) {
	r := &recorder{}
	if err := NewLinux(r.run).Set("https://y.test", "yakdev_secret"); err != nil {
		t.Fatal(err)
	}
	c := r.calls[0]
	if c.name != "secret-tool" || c.stdin != "yakdev_secret" {
		t.Fatalf("%+v", c)
	}
	if strings.Contains(strings.Join(c.args, " "), "yakdev_secret") {
		t.Fatalf("token leaked into argv: %v", c.args)
	}
	want := []string{"store", "--label", "Yak CLI", "service", "yak-cli", "account", "https://y.test"}
	if !reflect.DeepEqual(c.args, want) {
		t.Fatalf("%v", c.args)
	}
}

func TestLinuxGetAndDeleteArgv(t *testing.T) {
	r := &recorder{stdout: "tok"}
	s := NewLinux(r.run)
	if tok, err := s.Get("u"); err != nil || tok != "tok" {
		t.Fatal(tok, err)
	}
	_ = s.Delete("u")
	if !reflect.DeepEqual(r.calls[0].args, []string{"lookup", "service", "yak-cli", "account", "u"}) ||
		!reflect.DeepEqual(r.calls[1].args, []string{"clear", "service", "yak-cli", "account", "u"}) {
		t.Fatalf("%+v", r.calls)
	}
}

func TestNotFoundMaps(t *testing.T) {
	mac := NewMacOS((&recorder{err: &ExitError{Code: 44}}).run)
	if _, err := mac.Get("u"); !errors.Is(err, ErrNotFound) {
		t.Fatalf("mac get: %v", err)
	}
	if err := mac.Delete("u"); !errors.Is(err, ErrNotFound) {
		t.Fatalf("mac delete: %v", err)
	}
	lin := NewLinux((&recorder{err: &ExitError{Code: 1}}).run)
	if _, err := lin.Get("u"); !errors.Is(err, ErrNotFound) {
		t.Fatalf("linux get: %v", err)
	}
	other := NewMacOS((&recorder{err: &ExitError{Code: 2}}).run)
	if _, err := other.Get("u"); err == nil || errors.Is(err, ErrNotFound) {
		t.Fatalf("other exit code must stay an error: %v", err)
	}
}

func TestMemoryStore(t *testing.T) {
	m := NewMemory()
	if _, err := m.Get("u"); !errors.Is(err, ErrNotFound) {
		t.Fatal(err)
	}
	_ = m.Set("u", "t")
	if tok, _ := m.Get("u"); tok != "t" {
		t.Fatal(tok)
	}
	_ = m.Delete("u")
	if _, err := m.Get("u"); !errors.Is(err, ErrNotFound) {
		t.Fatal(err)
	}
}
