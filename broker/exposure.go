package main

import (
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io/fs"
	"os"
	"strings"
)

var errCredentialExposure = errors.New("credential_exposure")

// ExposureChecker decides whether a mounted credentials file may be exposed to a runner.
type ExposureChecker interface{ Check(path string) error }

type fileExposureChecker struct{ allowed map[string]bool }

// NewFileExposureChecker allows a claudeAiOauth login only when the sha256 of its
// refresh token is one of the shared Yak account digests.
func NewFileExposureChecker(allowedRefreshTokenSHA256 []string) ExposureChecker {
	allowed := map[string]bool{}
	for _, digest := range allowedRefreshTokenSHA256 {
		if digest = strings.ToLower(strings.TrimSpace(digest)); digest != "" {
			allowed[digest] = true
		}
	}
	return fileExposureChecker{allowed: allowed}
}

// Check fails closed: an unreadable or unparseable file is refused. It never
// returns an error that carries file content.
func (c fileExposureChecker) Check(path string) error {
	data, err := os.ReadFile(path)
	if errors.Is(err, fs.ErrNotExist) {
		return nil
	}
	if err != nil {
		return errCredentialExposure
	}
	var file struct {
		ClaudeAiOauth *struct {
			RefreshToken string `json:"refreshToken"`
		} `json:"claudeAiOauth"`
	}
	if json.Unmarshal(data, &file) != nil {
		return errCredentialExposure
	}
	if file.ClaudeAiOauth == nil {
		return nil
	}
	sum := sha256.Sum256([]byte(file.ClaudeAiOauth.RefreshToken))
	if c.allowed[hex.EncodeToString(sum[:])] {
		return nil
	}
	return errCredentialExposure
}
