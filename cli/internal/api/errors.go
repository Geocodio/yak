package api

import (
	"errors"
	"fmt"
)

// Error is the contract error envelope plus the HTTP status.
type Error struct {
	Status  int
	Code    string
	Message string
	Details map[string]any
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
