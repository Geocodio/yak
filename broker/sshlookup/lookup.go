package main

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"regexp"
	"strings"
)

var fingerprintPattern = regexp.MustCompile(`^SHA256:[A-Za-z0-9+/]{43}$`)

// Lookup asks Yak which authorized_keys lines belong to a key fingerprint.
// An invalid fingerprint makes no request.
func Lookup(ctx context.Context, baseURL, token, fingerprint string) ([]string, error) {
	if !fingerprintPattern.MatchString(fingerprint) {
		return nil, errors.New("invalid fingerprint")
	}

	body, err := json.Marshal(map[string]string{"fingerprint": fingerprint})
	if err != nil {
		return nil, err
	}

	request, err := http.NewRequestWithContext(ctx, http.MethodPost, strings.TrimRight(baseURL, "/")+"/internal/ssh/authorized-keys", bytes.NewReader(body))
	if err != nil {
		return nil, err
	}
	request.Header.Set("Authorization", "Bearer "+token)
	request.Header.Set("Content-Type", "application/json")
	request.Header.Set("Accept", "application/json")

	response, err := http.DefaultClient.Do(request)
	if err != nil {
		return nil, errors.New("lookup request failed")
	}
	defer response.Body.Close()

	if response.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("lookup status %d", response.StatusCode)
	}

	var payload struct {
		Lines []string `json:"lines"`
	}
	if err := json.NewDecoder(response.Body).Decode(&payload); err != nil {
		return nil, err
	}

	return payload.Lines, nil
}
