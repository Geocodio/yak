package main

import (
	"bufio"
	"bytes"
	"encoding/binary"
	"errors"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

// testClient is a minimal masking WebSocket client over a raw connection.
type testClient struct {
	conn   net.Conn
	reader *bufio.Reader
}

func newServer(t *testing.T, opts UpgradeOptions, handle func(*Conn)) *httptest.Server {
	t.Helper()
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		conn, err := Upgrade(w, r, opts)
		if err != nil {
			return
		}
		handle(conn)
	}))
	t.Cleanup(server.Close)

	return server
}

func allowAll(string) bool { return true }

func dial(t *testing.T, server *httptest.Server, origin string) (*testClient, *http.Response) {
	t.Helper()

	return dialPath(t, server, "/", origin)
}

func dialPath(t *testing.T, server *httptest.Server, path, origin string) (*testClient, *http.Response) {
	t.Helper()
	conn, err := net.Dial("tcp", strings.TrimPrefix(server.URL, "http://"))
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { conn.Close() })

	request := "GET " + path + " HTTP/1.1\r\nHost: x\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Version: 13\r\n" +
		"Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
	if origin != "" {
		request += "Origin: " + origin + "\r\n"
	}
	conn.Write([]byte(request + "\r\n"))

	reader := bufio.NewReader(conn)
	response, err := http.ReadResponse(reader, nil)
	if err != nil {
		t.Fatal(err)
	}

	return &testClient{conn: conn, reader: reader}, response
}

func (c *testClient) send(fin bool, op byte, payload []byte, masked bool) {
	b0 := op
	if fin {
		b0 |= 0x80
	}
	frame := []byte{b0}
	maskBit := byte(0)
	if masked {
		maskBit = 0x80
	}
	switch n := len(payload); {
	case n < 126:
		frame = append(frame, maskBit|byte(n))
	case n <= 0xFFFF:
		frame = append(frame, maskBit|126, byte(n>>8), byte(n))
	default:
		frame = append(frame, maskBit|127)
		frame = binary.BigEndian.AppendUint64(frame, uint64(n))
	}
	if masked {
		mask := []byte{1, 2, 3, 4}
		frame = append(frame, mask...)
		for i, b := range payload {
			frame = append(frame, b^mask[i%4])
		}
	} else {
		frame = append(frame, payload...)
	}
	c.conn.Write(frame)
}

// sendHeaderOnly announces a payload length without sending the payload.
func (c *testClient) sendHeaderOnly(op byte, length uint64) {
	frame := []byte{0x80 | op, 0x80 | 127}
	frame = binary.BigEndian.AppendUint64(frame, length)
	frame = append(frame, 1, 2, 3, 4)
	c.conn.Write(frame)
}

func (c *testClient) read(t *testing.T) (byte, []byte) {
	t.Helper()
	c.conn.SetReadDeadline(time.Now().Add(3 * time.Second))
	var head [2]byte
	if _, err := c.reader.Read(head[:1]); err != nil {
		t.Fatal(err)
	}
	if _, err := c.reader.Read(head[1:]); err != nil {
		t.Fatal(err)
	}
	length := int(head[1] & 0x7F)
	switch length {
	case 126:
		var ext [2]byte
		c.reader.Read(ext[:1])
		c.reader.Read(ext[1:])
		length = int(binary.BigEndian.Uint16(ext[:]))
	case 127:
		var ext [8]byte
		for i := range ext {
			c.reader.Read(ext[i : i+1])
		}
		length = int(binary.BigEndian.Uint64(ext[:]))
	}
	payload := make([]byte, length)
	for read := 0; read < length; {
		n, err := c.reader.Read(payload[read:])
		if err != nil {
			t.Fatal(err)
		}
		read += n
	}

	return head[0] & 0x0F, payload
}

func closeCode(payload []byte) int { return int(binary.BigEndian.Uint16(payload)) }

func TestAcceptKeyMatchesRFCSample(t *testing.T) {
	if got := AcceptKey("dGhlIHNhbXBsZSBub25jZQ=="); got != "s3pPLMBiTxaQ9kYGzzhZRbK+xOo=" {
		t.Fatalf("accept key %q", got)
	}
}

func TestBadOriginIsForbiddenWithoutUpgrade(t *testing.T) {
	called := false
	server := newServer(t, UpgradeOptions{CheckOrigin: func(o string) bool { return o == "https://ok" }}, func(*Conn) { called = true })

	_, response := dial(t, server, "https://evil")
	if response.StatusCode != 403 || called {
		t.Fatalf("status %d called %v", response.StatusCode, called)
	}
}

func TestEchoRoundTripAndLengthForms(t *testing.T) {
	server := newServer(t, UpgradeOptions{CheckOrigin: allowAll}, func(c *Conn) {
		for {
			op, payload, err := c.ReadMessage()
			if err != nil {
				return
			}
			if op == OpBinary {
				c.WriteBinary(payload)
			} else {
				c.WriteText(payload)
			}
		}
	})
	client, response := dial(t, server, "")
	if response.StatusCode != 101 {
		t.Fatalf("status %d", response.StatusCode)
	}

	for _, size := range []int{5, 125, 126, 127, 65535, 1} {
		if size > 60000 {
			continue
		}
		body := bytes.Repeat([]byte("a"), size)
		client.send(true, opBinary, body, true)
		op, got := client.read(t)
		if op != opBinary || !bytes.Equal(got, body) {
			t.Fatalf("size %d: op %d len %d", size, op, len(got))
		}
	}

	client.send(true, opText, []byte("hi"), true)
	if op, got := client.read(t); op != opText || string(got) != "hi" {
		t.Fatalf("text echo %d %q", op, got)
	}
}

func TestUnmaskedFrameClosesWith1002(t *testing.T) {
	var readErr error
	done := make(chan struct{})
	server := newServer(t, UpgradeOptions{CheckOrigin: allowAll}, func(c *Conn) {
		_, _, readErr = c.ReadMessage()
		close(done)
	})
	client, _ := dial(t, server, "")
	client.send(true, opBinary, []byte("x"), false)

	op, payload := client.read(t)
	<-done
	if op != opClose || closeCode(payload) != 1002 || !errors.Is(readErr, ErrProtocol) {
		t.Fatalf("op %d err %v", op, readErr)
	}
}

func TestOversizedFrameClosesWith1009BeforePayload(t *testing.T) {
	var readErr error
	done := make(chan struct{})
	server := newServer(t, UpgradeOptions{CheckOrigin: allowAll, MaxFrame: 1024}, func(c *Conn) {
		_, _, readErr = c.ReadMessage()
		close(done)
	})
	client, _ := dial(t, server, "")
	client.sendHeaderOnly(opBinary, 1<<20)

	op, payload := client.read(t)
	<-done
	if op != opClose || closeCode(payload) != 1009 || !errors.Is(readErr, ErrFrameTooLarge) {
		t.Fatalf("op %d err %v", op, readErr)
	}
}

func TestFragmentsReassembleWithControlFrameInBetween(t *testing.T) {
	got := make(chan string, 1)
	server := newServer(t, UpgradeOptions{CheckOrigin: allowAll}, func(c *Conn) {
		_, payload, _ := c.ReadMessage()
		got <- string(payload)
	})
	client, _ := dial(t, server, "")

	client.send(false, opText, []byte("hel"), true)
	client.send(true, opPing, []byte("p"), true)
	client.send(false, opContinuation, []byte("lo "), true)
	client.send(true, opContinuation, []byte("world"), true)

	if op, payload := client.read(t); op != opPong || string(payload) != "p" {
		t.Fatalf("expected pong, got %d %q", op, payload)
	}
	if message := <-got; message != "hello world" {
		t.Fatalf("message %q", message)
	}
}

func TestPeerCloseReturnsErrClosed(t *testing.T) {
	errs := make(chan error, 1)
	server := newServer(t, UpgradeOptions{CheckOrigin: allowAll}, func(c *Conn) {
		_, _, err := c.ReadMessage()
		errs <- err
	})
	client, _ := dial(t, server, "")
	client.send(true, opClose, []byte{0x03, 0xE8}, true)

	if err := <-errs; !errors.Is(err, ErrClosed) {
		t.Fatalf("err %v", err)
	}
}
