package api

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net"
)

// Error is the contract error envelope plus the HTTP status.
type Error struct {
	Status  int
	Code    string
	Message string
	Details map[string]any
	// Body is the whole decoded envelope, for routes that put fields such as reason or length beside error.
	Body map[string]any
}

func (e *Error) Error() string {
	if e.Message != "" {
		return e.Message
	}
	return fmt.Sprintf("%s (HTTP %d)", e.Code, e.Status)
}

// Is lets errors.Is(err, ErrProtocolTooOld) match any protocol_too_old error.
func (e *Error) Is(target error) bool {
	return target == ErrProtocolTooOld && e.Code == "protocol_too_old"
}

// ErrProtocolTooOld is the sentinel for HTTP 426; real errors are *Error values with the server message.
var ErrProtocolTooOld = &Error{Status: 426, Code: "protocol_too_old", Message: "This yak command is too old for Yak. Update the Yak plugin."}

// AsError unwraps an *Error.
func AsError(err error) (*Error, bool) {
	var e *Error
	if errors.As(err, &e) {
		return e, true
	}
	return nil, false
}

// IsCode reports whether err is an API error with the given code.
func IsCode(err error, code string) bool {
	e, ok := AsError(err)
	return ok && e.Code == code
}

// IsTransient reports whether err looks like the network or an overloaded server rather than a real answer:
// a transport error or timeout, 429, or a 5xx. A cancelled context is not transient.
func IsTransient(err error) bool {
	if err == nil || errors.Is(err, context.Canceled) {
		return false
	}
	e, ok := AsError(err)
	if !ok {
		var netErr net.Error
		return errors.Is(err, context.DeadlineExceeded) || errors.Is(err, io.ErrUnexpectedEOF) || errors.Is(err, io.EOF) || errors.As(err, &netErr)
	}
	return e.Status == 429 || e.Status >= 500
}
