package termbridge

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"os"
	"strings"
	"time"
)

// ErrDenied is returned when Yak refuses a terminal token for any reason.
var ErrDenied = errors.New("terminal token denied")

// Grant is what Yak returns for a redeemed terminal token.
type Grant struct {
	ThreadID  int64
	Address   string
	Container string
	TTL       time.Duration
}

// Redeemer exchanges a one-time terminal token and a public key for a Grant.
type Redeemer interface {
	Redeem(ctx context.Context, token, publicKey, origin string) (Grant, error)
}

// YakClient redeems terminal tokens against POST /internal/ssh/terminal/redeem.
type YakClient struct {
	BaseURL   string
	TokenFile string
	HTTP      *http.Client
}

// Redeem never includes the token, the key or the response body in its errors.
func (c *YakClient) Redeem(ctx context.Context, token, publicKey, origin string) (Grant, error) {
	secret, err := os.ReadFile(c.TokenFile)
	if err != nil {
		return Grant{}, errors.New("yak token file unreadable")
	}

	payload, _ := json.Marshal(map[string]string{"token": token, "public_key": publicKey, "origin": origin})

	ctx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()

	request, err := http.NewRequestWithContext(ctx, http.MethodPost,
		strings.TrimRight(c.BaseURL, "/")+"/internal/ssh/terminal/redeem", bytes.NewReader(payload))
	if err != nil {
		return Grant{}, errors.New("yak redeem request invalid")
	}
	request.Header.Set("Authorization", "Bearer "+strings.TrimSpace(string(secret)))
	request.Header.Set("Content-Type", "application/json")
	request.Header.Set("Accept", "application/json")

	client := c.HTTP
	if client == nil {
		client = http.DefaultClient
	}

	response, err := client.Do(request)
	if err != nil {
		return Grant{}, errors.New("yak redeem request failed")
	}
	defer response.Body.Close()

	if response.StatusCode != http.StatusOK {
		return Grant{}, ErrDenied
	}

	var reply struct {
		ThreadID  int64  `json:"thread_id"`
		Address   string `json:"address"`
		Container string `json:"container"`
		TTL       int    `json:"ttl"`
	}
	if err := json.NewDecoder(response.Body).Decode(&reply); err != nil || reply.Address == "" {
		return Grant{}, ErrDenied
	}

	return Grant{
		ThreadID:  reply.ThreadID,
		Address:   reply.Address,
		Container: reply.Container,
		TTL:       time.Duration(reply.TTL) * time.Second,
	}, nil
}
