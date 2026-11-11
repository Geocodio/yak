package config

import (
	"os"
	"path/filepath"
	"testing"
)

func TestDirPrefersYakHome(t *testing.T) {
	t.Setenv("YAK_HOME", "/tmp/yakhome")
	t.Setenv("XDG_CONFIG_HOME", "/tmp/xdg")
	got, err := Dir()
	if err != nil || got != "/tmp/yakhome" {
		t.Fatalf("got %q, %v", got, err)
	}
	t.Setenv("YAK_HOME", "")
	got, _ = Dir()
	if got != "/tmp/xdg/yak" {
		t.Fatalf("got %q", got)
	}
	t.Setenv("XDG_CONFIG_HOME", "")
	t.Setenv("HOME", "/tmp/h")
	got, _ = Dir()
	if got != "/tmp/h/.config/yak" {
		t.Fatalf("got %q", got)
	}
}

func TestSaveIsAtomicAndPrivate(t *testing.T) {
	dir := filepath.Join(t.TempDir(), "yak")
	c := Config{BaseURL: "https://x.test", DeviceName: "box", Checkouts: map[string]string{"a/b": "/p"}}
	if err := c.Save(dir); err != nil {
		t.Fatal(err)
	}
	fi, err := os.Stat(filepath.Join(dir, "config.json"))
	if err != nil || fi.Mode().Perm() != 0o600 {
		t.Fatalf("file mode %v, %v", fi.Mode(), err)
	}
	di, _ := os.Stat(dir)
	if di.Mode().Perm() != 0o700 {
		t.Fatalf("dir mode %v", di.Mode())
	}
	entries, _ := os.ReadDir(dir)
	if len(entries) != 1 {
		t.Fatalf("temp file left behind: %v", entries)
	}
	got, err := Load(dir)
	if err != nil || got.BaseURL != c.BaseURL || got.Checkouts["a/b"] != "/p" {
		t.Fatalf("roundtrip %+v, %v", got, err)
	}
}

func TestLoadMissingReturnsZero(t *testing.T) {
	got, err := Load(t.TempDir())
	if err != nil || got.BaseURL != "" || got.Checkouts != nil && len(got.Checkouts) != 0 {
		t.Fatalf("got %+v, %v", got, err)
	}
}
