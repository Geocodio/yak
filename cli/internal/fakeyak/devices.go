package fakeyak

import (
	"encoding/json"
	"fmt"
	"net/http"

	"github.com/geocodio/yak/cli/internal/api"
)

// Device is a paired computer.
type Device struct {
	ID      int
	Name    string
	OS      string
	Arch    string
	Token   string
	Revoked bool
}

func (d *Device) info() api.DeviceInfo {
	return api.DeviceInfo{ID: d.ID, Name: d.Name, OS: d.OS, Arch: d.Arch}
}

type pendingCode struct {
	userCode string
	status   string // pending, approved, denied, expired
	name     string
	os, arch string
	device   *Device
	redeemed bool
}

// AddDevice registers a device and returns the token response as if it had just been approved.
func (s *Server) AddDevice(name string) api.TokenResponse {
	s.mu.Lock()
	defer s.mu.Unlock()
	d := s.addDevice(name, "darwin", "arm64")
	return api.TokenResponse{Token: d.Token, Device: d.info(), User: s.User}
}

func (s *Server) addDevice(name, os, arch string) *Device {
	id := s.newID()
	d := &Device{ID: id, Name: name, OS: os, Arch: arch, Token: fmt.Sprintf("yakdev_fake%036d", id)}
	s.Devices[id] = d
	return d
}

// RevokeDevice marks a device revoked, as Settings, Devices would.
func (s *Server) RevokeDevice(id int) {
	s.mu.Lock()
	defer s.mu.Unlock()
	if d, ok := s.Devices[id]; ok {
		d.Revoked = true
	}
}

func (s *Server) setCodeStatus(userCode, status string) {
	s.mu.Lock()
	defer s.mu.Unlock()
	for _, c := range s.codes {
		if c.userCode == userCode && c.status == "pending" {
			c.status = status
			if status == "approved" {
				c.device = s.addDevice(c.name, c.os, c.arch)
			}
		}
	}
}

// ApproveCode simulates the person pressing Approve on the web page.
func (s *Server) ApproveCode(userCode string) { s.setCodeStatus(userCode, "approved") }

// DenyCode simulates "This is not me".
func (s *Server) DenyCode(userCode string) { s.setCodeStatus(userCode, "denied") }

// ExpireCode simulates the 10 minute window passing.
func (s *Server) ExpireCode(userCode string) { s.setCodeStatus(userCode, "expired") }

func (s *Server) deviceCode(w http.ResponseWriter, r *http.Request) {
	var req api.DeviceCodeRequest
	_ = json.NewDecoder(r.Body).Decode(&req)
	n := s.newID()
	c := &pendingCode{userCode: fmt.Sprintf("WDJB-MJ%02d", n), status: "pending", name: req.Name, os: req.OS, arch: req.Arch}
	deviceCode := fmt.Sprintf("device-code-%d", n)
	s.codes[deviceCode] = c
	writeJSON(w, 200, api.DeviceCode{
		DeviceCode: deviceCode, UserCode: c.userCode,
		VerificationURL:         s.srv.URL + "/device",
		VerificationURLComplete: s.srv.URL + "/device?code=" + c.userCode,
		ExpiresIn:               600, Interval: 5,
	})
}

func (s *Server) deviceToken(w http.ResponseWriter, r *http.Request) {
	var req struct {
		DeviceCode string `json:"device_code"`
	}
	_ = json.NewDecoder(r.Body).Decode(&req)
	c, ok := s.codes[req.DeviceCode]
	if !ok || c.redeemed || c.status == "expired" {
		writeErr(w, 400, "expired_token", "That code expired.", nil)
		return
	}
	if s.SlowDownNext > 0 {
		s.SlowDownNext--
		writeErr(w, 400, "slow_down", "Polled too fast.", nil)
		return
	}
	switch c.status {
	case "pending":
		writeErr(w, 400, "authorization_pending", "Waiting for approval.", nil)
	case "denied":
		writeErr(w, 400, "access_denied", "The code was denied.", nil)
	default:
		c.redeemed = true
		writeJSON(w, 200, api.TokenResponse{Token: c.device.Token, Device: c.device.info(), User: s.User})
	}
}

// LastUserCode returns the user code of the most recently issued device code.
func (s *Server) LastUserCode() string {
	s.mu.Lock()
	defer s.mu.Unlock()
	last, best := "", 0
	for dc, c := range s.codes {
		var n int
		fmt.Sscanf(dc, "device-code-%d", &n)
		if n > best {
			best, last = n, c.userCode
		}
	}
	return last
}
