// Package termbridge bridges a browser WebSocket to a local ssh client running under a PTY.
package termbridge

import (
	"bufio"
	"crypto/sha1"
	"encoding/base64"
	"encoding/binary"
	"errors"
	"io"
	"net"
	"net/http"
	"strings"
	"sync"
	"time"
)

const (
	opContinuation = 0x0
	opText         = 0x1
	opBinary       = 0x2
	opClose        = 0x8
	opPing         = 0x9
	opPong         = 0xA

	// OpText and OpBinary are the message kinds ReadMessage returns.
	OpText   = opText
	OpBinary = opBinary

	defaultMaxFrame = 64 * 1024
	websocketGUID   = "258EAFA5-E914-47DA-95CA-C5AB0DC85B11"
)

var (
	// ErrClosed is returned once the peer has closed the connection.
	ErrClosed = errors.New("websocket closed")
	// ErrFrameTooLarge is returned after closing with code 1009.
	ErrFrameTooLarge = errors.New("websocket frame too large")
	// ErrProtocol is returned after closing with code 1002.
	ErrProtocol = errors.New("websocket protocol error")
)

// UpgradeOptions configures Upgrade.
type UpgradeOptions struct {
	// MaxFrame caps one frame and one reassembled message; zero means 64 KiB.
	MaxFrame int
	// CheckOrigin receives the Origin header (possibly empty) and must allow it.
	CheckOrigin func(origin string) bool
	// WriteTimeout bounds every write; zero means 10 seconds.
	WriteTimeout time.Duration
}

// Conn is the server side of one WebSocket connection. One goroutine may read while others write.
type Conn struct {
	raw          net.Conn
	reader       *bufio.Reader
	maxFrame     int
	writeTimeout time.Duration

	writeMu sync.Mutex
	closed  bool
}

// AcceptKey computes Sec-WebSocket-Accept for a client key.
func AcceptKey(key string) string {
	sum := sha1.Sum([]byte(key + websocketGUID))

	return base64.StdEncoding.EncodeToString(sum[:])
}

func headerHasToken(header http.Header, name, token string) bool {
	for _, value := range header.Values(name) {
		for _, part := range strings.Split(value, ",") {
			if strings.EqualFold(strings.TrimSpace(part), token) {
				return true
			}
		}
	}

	return false
}

// Upgrade validates the handshake, checks the origin and takes over the connection.
func Upgrade(w http.ResponseWriter, r *http.Request, opts UpgradeOptions) (*Conn, error) {
	if opts.CheckOrigin == nil {
		return nil, errors.New("CheckOrigin is required")
	}
	if r.Method != http.MethodGet {
		http.Error(w, "method not allowed", http.StatusMethodNotAllowed)
		return nil, errors.New("method not allowed")
	}
	if !opts.CheckOrigin(r.Header.Get("Origin")) {
		http.Error(w, "forbidden", http.StatusForbidden)
		return nil, errors.New("origin not allowed")
	}

	key := r.Header.Get("Sec-WebSocket-Key")
	if !headerHasToken(r.Header, "Connection", "upgrade") ||
		!headerHasToken(r.Header, "Upgrade", "websocket") ||
		r.Header.Get("Sec-WebSocket-Version") != "13" || key == "" {
		http.Error(w, "bad handshake", http.StatusBadRequest)
		return nil, errors.New("bad handshake")
	}

	hijacker, ok := w.(http.Hijacker)
	if !ok {
		http.Error(w, "cannot hijack", http.StatusInternalServerError)
		return nil, errors.New("cannot hijack")
	}
	raw, rw, err := hijacker.Hijack()
	if err != nil {
		return nil, err
	}

	maxFrame := opts.MaxFrame
	if maxFrame <= 0 {
		maxFrame = defaultMaxFrame
	}
	writeTimeout := opts.WriteTimeout
	if writeTimeout <= 0 {
		writeTimeout = 10 * time.Second
	}

	response := "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: " +
		AcceptKey(key) + "\r\n\r\n"
	_ = raw.SetWriteDeadline(time.Now().Add(writeTimeout))
	if _, err := raw.Write([]byte(response)); err != nil {
		raw.Close()
		return nil, err
	}

	return &Conn{raw: raw, reader: rw.Reader, maxFrame: maxFrame, writeTimeout: writeTimeout}, nil
}

// SetReadDeadline bounds the next ReadMessage.
func (c *Conn) SetReadDeadline(t time.Time) error { return c.raw.SetReadDeadline(t) }

func (c *Conn) writeFrame(op byte, payload []byte) error {
	c.writeMu.Lock()
	defer c.writeMu.Unlock()

	return c.writeFrameLocked(op, payload)
}

func (c *Conn) writeFrameLocked(op byte, payload []byte) error {
	if c.closed {
		return ErrClosed
	}

	header := []byte{0x80 | op}
	switch n := len(payload); {
	case n < 126:
		header = append(header, byte(n))
	case n <= 0xFFFF:
		header = append(header, 126, byte(n>>8), byte(n))
	default:
		header = append(header, 127)
		header = binary.BigEndian.AppendUint64(header, uint64(n))
	}

	_ = c.raw.SetWriteDeadline(time.Now().Add(c.writeTimeout))
	if _, err := c.raw.Write(append(header, payload...)); err != nil {
		return err
	}

	return nil
}

// WriteBinary sends one binary message.
func (c *Conn) WriteBinary(payload []byte) error { return c.writeFrame(opBinary, payload) }

// WriteText sends one text message.
func (c *Conn) WriteText(payload []byte) error { return c.writeFrame(opText, payload) }

// Close sends a close frame (once) and closes the connection.
func (c *Conn) Close(code int, reason string) error {
	c.writeMu.Lock()
	defer c.writeMu.Unlock()

	if c.closed {
		return nil
	}
	if len(reason) > 123 {
		reason = reason[:123]
	}
	body := append([]byte{byte(code >> 8), byte(code)}, reason...)
	err := c.writeFrameLocked(opClose, body)
	c.closed = true
	c.raw.Close()

	return err
}

func (c *Conn) fail(code int, reason string, err error) error {
	_ = c.Close(code, reason)

	return err
}

// ReadMessage returns the next complete data message. Pings are answered and close frames end the stream.
func (c *Conn) ReadMessage() (byte, []byte, error) {
	var (
		message   []byte
		messageOp byte
		inMessage bool
	)

	for {
		var head [2]byte
		if _, err := io.ReadFull(c.reader, head[:]); err != nil {
			return 0, nil, ErrClosed
		}

		fin := head[0]&0x80 != 0
		op := head[0] & 0x0F
		if head[0]&0x70 != 0 {
			return 0, nil, c.fail(1002, "reserved bits", ErrProtocol)
		}
		if head[1]&0x80 == 0 {
			return 0, nil, c.fail(1002, "unmasked frame", ErrProtocol)
		}

		length := uint64(head[1] & 0x7F)
		switch length {
		case 126:
			var ext [2]byte
			if _, err := io.ReadFull(c.reader, ext[:]); err != nil {
				return 0, nil, ErrClosed
			}
			length = uint64(binary.BigEndian.Uint16(ext[:]))
		case 127:
			var ext [8]byte
			if _, err := io.ReadFull(c.reader, ext[:]); err != nil {
				return 0, nil, ErrClosed
			}
			length = binary.BigEndian.Uint64(ext[:])
		}

		isControl := op >= 0x8
		if isControl && (!fin || length > 125) {
			return 0, nil, c.fail(1002, "bad control frame", ErrProtocol)
		}
		if length > uint64(c.maxFrame) || (!isControl && uint64(len(message))+length > uint64(c.maxFrame)) {
			return 0, nil, c.fail(1009, "frame too large", ErrFrameTooLarge)
		}

		var mask [4]byte
		if _, err := io.ReadFull(c.reader, mask[:]); err != nil {
			return 0, nil, ErrClosed
		}
		payload := make([]byte, length)
		if _, err := io.ReadFull(c.reader, payload); err != nil {
			return 0, nil, ErrClosed
		}
		for i := range payload {
			payload[i] ^= mask[i%4]
		}

		switch op {
		case opPing:
			if err := c.writeFrame(opPong, payload); err != nil {
				return 0, nil, ErrClosed
			}
			continue
		case opPong:
			continue
		case opClose:
			code := 1000
			if len(payload) >= 2 {
				code = int(binary.BigEndian.Uint16(payload))
			}
			_ = c.Close(code, "")

			return 0, nil, ErrClosed
		case opContinuation:
			if !inMessage {
				return 0, nil, c.fail(1002, "unexpected continuation", ErrProtocol)
			}
		case opText, opBinary:
			if inMessage {
				return 0, nil, c.fail(1002, "interleaved data frame", ErrProtocol)
			}
			inMessage = true
			messageOp = op
		default:
			return 0, nil, c.fail(1002, "unknown opcode", ErrProtocol)
		}

		message = append(message, payload...)
		if fin {
			return messageOp, message, nil
		}
	}
}
