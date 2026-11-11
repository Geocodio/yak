package main

import (
	"context"
	"log/slog"
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
