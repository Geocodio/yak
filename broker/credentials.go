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

// Credential is what the Yak app returns for a placeholder: the real secret
// and where the placeholder may be used from. Container names the sandbox the
// placeholder is bound to; Host marks a placeholder the host itself uses (account validation).
type Credential struct {
	Type      string `json:"type"`
	Value     string `json:"value"`
	Container string `json:"container"`
	Host      bool   `json:"host"`
}

// CredentialSource looks up the credential behind a placeholder.
type CredentialSource interface {
	Lookup(ctx context.Context, placeholder string) (Credential, error)
}

// SocketCredentialSource asks the Yak app over the Unix socket it serves.
// One request line goes in, one JSON line comes back. The credential stays in
// memory; it never touches Redis or disk.
type SocketCredentialSource struct {
	Path string
}

func (s SocketCredentialSource) Lookup(ctx context.Context, placeholder string) (Credential, error) {
	dialer := net.Dialer{Timeout: 5 * time.Second}
	connection, err := dialer.DialContext(ctx, "unix", s.Path)
	if err != nil {
		return Credential{}, fmt.Errorf("connect to credential socket: %w", err)
	}
	defer connection.Close()
	_ = connection.SetDeadline(time.Now().Add(10 * time.Second))

	request, _ := json.Marshal(map[string]string{"placeholder": placeholder})
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
