package main

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"time"
)

// Credential is the secret a runner authenticates with.
type Credential struct {
	Type  string `json:"type"`
	Value string `json:"value"`
}

// Redeemer exchanges a one-time reference for its credential.
type Redeemer interface {
	Redeem(ctx context.Context, reference string) (Credential, error)
}

// SocketRedeemer redeems references over the Unix socket the Yak app serves.
// One request line goes in, one JSON line comes back.
type SocketRedeemer struct {
	Path string
}

func (r SocketRedeemer) Redeem(ctx context.Context, reference string) (Credential, error) {
	dialer := net.Dialer{Timeout: 5 * time.Second}
	connection, err := dialer.DialContext(ctx, "unix", r.Path)
	if err != nil {
		return Credential{}, fmt.Errorf("connect to credential socket: %w", err)
	}
	defer connection.Close()
	_ = connection.SetDeadline(time.Now().Add(10 * time.Second))

	request, _ := json.Marshal(map[string]string{"reference": reference})
	if _, err := connection.Write(append(request, '\n')); err != nil {
		return Credential{}, fmt.Errorf("send credential request: %w", err)
	}
	reply, err := bufio.NewReader(connection).ReadBytes('\n')
	if err != nil {
		return Credential{}, fmt.Errorf("read credential reply: %w", err)
	}
	var response struct {
		Credential
		Error string `json:"error"`
	}
	if err := json.Unmarshal(reply, &response); err != nil {
		return Credential{}, errors.New("credential reply is not JSON")
	}
	if response.Error != "" || response.Value == "" {
		return Credential{}, fmt.Errorf("credential refused: %s", response.Error)
	}
	return response.Credential, nil
}
