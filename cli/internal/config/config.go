// Package config reads and writes the per-machine yak state directory.
package config

import (
	"encoding/json"
	"errors"
	"io/fs"
	"os"
	"path/filepath"
)

// Config is the contents of config.json.
type Config struct {
	BaseURL    string            `json:"base_url"`
	DeviceName string            `json:"device_name"`
	Checkouts  map[string]string `json:"checkouts,omitempty"`
}

// Dir returns the state directory: $YAK_HOME, else $XDG_CONFIG_HOME/yak, else ~/.config/yak.
func Dir() (string, error) {
	if d := os.Getenv("YAK_HOME"); d != "" {
		return d, nil
	}
	if d := os.Getenv("XDG_CONFIG_HOME"); d != "" {
		return filepath.Join(d, "yak"), nil
	}
	home, err := os.UserHomeDir()
	if err != nil {
		return "", err
	}
	return filepath.Join(home, ".config", "yak"), nil
}

// Load reads config.json from dir; a missing file yields the zero Config.
func Load(dir string) (Config, error) {
	var c Config
	b, err := os.ReadFile(filepath.Join(dir, "config.json"))
	if errors.Is(err, fs.ErrNotExist) {
		return c, nil
	}
	if err != nil {
		return c, err
	}
	err = json.Unmarshal(b, &c)
	return c, err
}

// Save writes config.json atomically with mode 0600 inside a 0700 directory.
func (c Config) Save(dir string) error {
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return err
	}
	b, err := json.MarshalIndent(c, "", "  ")
	if err != nil {
		return err
	}
	tmp, err := os.CreateTemp(dir, "config-*.tmp")
	if err != nil {
		return err
	}
	defer os.Remove(tmp.Name())
	if err := tmp.Chmod(0o600); err != nil {
		tmp.Close()
		return err
	}
	if _, err := tmp.Write(b); err != nil {
		tmp.Close()
		return err
	}
	if err := tmp.Close(); err != nil {
		return err
	}
	return os.Rename(tmp.Name(), filepath.Join(dir, "config.json"))
}
