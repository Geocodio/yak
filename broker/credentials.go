package main

import (
	"bufio"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"net"
	"time"
)

// Credential is the real secret behind a placeholder. Kind is "setup_token"
// or "api_key". Every formatting path prints "[redacted]" so the secret cannot
// reach a log line by accident.
type Credential struct {
	Kind      string
	Secret    string
	AccountID int64
}

func (Credential) String() string { return "[redacted]" }

func (Credential) GoString() string { return "[redacted]" }

func (Credential) LogValue() slog.Value { return slog.StringValue("[redacted]") }

// CredentialSource resolves a placeholder presented from containerIP.
type CredentialSource interface {
	Lookup(ctx context.Context, placeholder, containerIP string) (Credential, error)
}

// ErrUnknownPlaceholder means the app does not know the placeholder, or it is
// not bound to the address it was presented from.
var ErrUnknownPlaceholder = errors.New("unknown placeholder")

// maxReplyBytes caps the reply line read from the socket.
const maxReplyBytes = 64 << 10

// SocketCredentialSource asks the Yak app over its Unix credential socket.
// One request line goes out and one reply line comes back. Error text never
// includes reply bytes, so a secret cannot leak through an error.
type SocketCredentialSource struct {
	Path string
}

func (s SocketCredentialSource) Lookup(ctx context.Context, placeholder, containerIP string) (Credential, error) {
	dialer := net.Dialer{Timeout: 5 * time.Second}
	connection, err := dialer.DialContext(ctx, "unix", s.Path)
	if err != nil {
		return Credential{}, errors.New("connect to credential socket failed")
	}
	defer connection.Close()

	deadline := time.Now().Add(10 * time.Second)
	if contextDeadline, ok := ctx.Deadline(); ok && contextDeadline.Before(deadline) {
		deadline = contextDeadline
	}
	_ = connection.SetDeadline(deadline)
	// Closing the connection unblocks a read when the context is cancelled.
	stop := context.AfterFunc(ctx, func() { _ = connection.Close() })
	defer stop()

	request, err := json.Marshal(struct {
		Placeholder string `json:"placeholder"`
		ContainerIP string `json:"container_ip"`
	}{placeholder, containerIP})
	if err != nil {
		return Credential{}, errors.New("encode credential request failed")
	}
	if _, err := connection.Write(append(request, '\n')); err != nil {
		return Credential{}, errors.New("send credential request failed")
	}

	reply, err := bufio.NewReader(io.LimitReader(connection, maxReplyBytes)).ReadBytes('\n')
	if err != nil {
		return Credential{}, errors.New("read credential reply failed")
	}

	var response struct {
		Kind      string `json:"kind"`
		Secret    string `json:"secret"`
		AccountID int64  `json:"account_id"`
		Error     string `json:"error"`
	}
	if err := json.Unmarshal(reply, &response); err != nil {
		return Credential{}, errors.New("credential reply is not valid JSON")
	}
	if response.Error == "unknown" {
		return Credential{}, ErrUnknownPlaceholder
	}
	if response.Error != "" {
		return Credential{}, errors.New("credential service returned an error")
	}
	if response.Secret == "" {
		return Credential{}, errors.New("credential reply has no secret")
	}
	if response.Kind != "setup_token" && response.Kind != "api_key" {
		return Credential{}, fmt.Errorf("credential reply has an unsupported kind")
	}
	return Credential{Kind: response.Kind, Secret: response.Secret, AccountID: response.AccountID}, nil
}
