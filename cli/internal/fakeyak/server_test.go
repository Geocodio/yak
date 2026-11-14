package fakeyak

import (
	"context"
	"net/http"
	"testing"

	"github.com/geocodio/yak/cli/internal/api"
)

func client(s *Server, token string) *api.Client {
	c := api.New(s.URL(), token, nil, "yak-cli/0.1.0 (darwin; arm64)")
	c.Backoff = func(context.Context, int) error { return nil }
	return c
}

func TestDeviceFlowPendingThenApproved(t *testing.T) {
	s := New(t)
	c := client(s, "")
	ctx := context.Background()
	var code api.DeviceCode
	if err := c.Do(ctx, "POST", "/device/code", api.DeviceCodeRequest{Name: "box", OS: "darwin", Arch: "arm64", CLIVersion: "0.1.0"}, &code); err != nil {
		t.Fatal(err)
	}
	if code.UserCode == "" || code.Interval != 5 || code.ExpiresIn != 600 {
		t.Fatalf("%+v", code)
	}
	var tok api.TokenResponse
	err := c.Do(ctx, "POST", "/device/token", map[string]string{"device_code": code.DeviceCode}, &tok)
	if !api.IsCode(err, "authorization_pending") {
		t.Fatalf("want pending, got %v", err)
	}
	s.ApproveCode(code.UserCode)
	if err := c.Do(ctx, "POST", "/device/token", map[string]string{"device_code": code.DeviceCode}, &tok); err != nil {
		t.Fatal(err)
	}
	if tok.Token == "" || tok.Device.Name != "box" || tok.User.Email == "" {
		t.Fatalf("%+v", tok)
	}
	err = c.Do(ctx, "POST", "/device/token", map[string]string{"device_code": code.DeviceCode}, &tok)
	if !api.IsCode(err, "expired_token") {
		t.Fatalf("a redeemed code must be expired_token, got %v", err)
	}
	var me api.Me
	if err := client(s, tok.Token).Do(ctx, "GET", "/me", nil, &me); err != nil || me.Device.ID != tok.Device.ID {
		t.Fatalf("%+v %v", me, err)
	}
}

func TestDeniedAndSlowDown(t *testing.T) {
	s := New(t)
	c := client(s, "")
	ctx := context.Background()
	var code api.DeviceCode
	_ = c.Do(ctx, "POST", "/device/code", api.DeviceCodeRequest{Name: "b", OS: "linux", Arch: "x86_64"}, &code)
	s.SlowDownNext = 1
	poll := map[string]string{"device_code": code.DeviceCode}
	if err := c.Do(ctx, "POST", "/device/token", poll, nil); !api.IsCode(err, "slow_down") {
		t.Fatal(err)
	}
	s.DenyCode(code.UserCode)
	if err := c.Do(ctx, "POST", "/device/token", poll, nil); !api.IsCode(err, "access_denied") {
		t.Fatal(err)
	}
}

func TestRevokedDeviceGets401DeviceRevoked(t *testing.T) {
	s := New(t)
	tok := s.AddDevice("box")
	c := client(s, tok.Token)
	ctx := context.Background()
	s.RevokeDevice(tok.Device.ID)
	if err := c.Do(ctx, "GET", "/me", nil, nil); !api.IsCode(err, "device_revoked") {
		t.Fatal(err)
	}
	if err := client(s, "yakdev_nope").Do(ctx, "GET", "/me", nil, nil); !api.IsCode(err, "invalid_token") {
		t.Fatal(err)
	}
	if err := client(s, "").Do(ctx, "GET", "/me", nil, nil); !api.IsCode(err, "unauthenticated") {
		t.Fatal(err)
	}
}

func TestDeleteDeviceRevokesAndRepeatIs401(t *testing.T) {
	s := New(t)
	tok := s.AddDevice("box")
	c := client(s, tok.Token)
	if err := c.Do(context.Background(), "DELETE", "/device", nil, nil); err != nil {
		t.Fatal(err)
	}
	if err := c.Do(context.Background(), "DELETE", "/device", nil, nil); !api.IsCode(err, "device_revoked") {
		t.Fatal(err)
	}
}

func TestResolveRepoNotOnYakIs200OnYakFalse(t *testing.T) {
	s := New(t)
	s.Repos["acme/widgets"] = Repo{Slug: "acme/widgets", DefaultBranch: "main", CloneURL: "https://github.com/acme/widgets.git"}
	c := client(s, s.AddDevice("box").Token)
	var res api.ResolveResult
	err := c.Do(context.Background(), "POST", "/repos/resolve", api.ResolveRequest{Remotes: []string{
		"git@github.com:acme/widgets.git", "https://github.com/acme/other.git",
	}}, &res)
	if err != nil || len(res.Repos) != 2 {
		t.Fatalf("%+v %v", res, err)
	}
	if !res.Repos[0].OnYak || *res.Repos[0].Repo != "acme/widgets" || *res.Repos[0].DefaultBranch != "main" {
		t.Fatalf("%+v", res.Repos[0])
	}
	if res.Repos[1].OnYak || res.Repos[1].Repo != nil || res.Repos[1].CloneURL != nil {
		t.Fatalf("%+v", res.Repos[1])
	}
}

func TestThreadLookupAndBySession(t *testing.T) {
	s := New(t)
	s.Threads[42] = &Thread{ID: 42, Title: "Fix", ActiveSessionID: "sid-1", OwnerSide: "yak"}
	c := client(s, s.AddDevice("box").Token)
	ctx := context.Background()
	var r api.ThreadResponse
	if err := c.Do(ctx, "GET", "/threads/42", nil, &r); err != nil || r.Thread.Title != "Fix" {
		t.Fatalf("%+v %v", r, err)
	}
	if err := c.Do(ctx, "GET", "/threads/by-session/sid-1", nil, &r); err != nil || r.Thread.ID != 42 {
		t.Fatalf("%+v %v", r, err)
	}
	if err := c.Do(ctx, "GET", "/threads/99", nil, nil); !api.IsCode(err, "thread_not_found") {
		t.Fatal(err)
	}
	if err := c.Do(ctx, "GET", "/threads/by-session/none", nil, nil); !api.IsCode(err, "thread_not_found") {
		t.Fatal(err)
	}
}

func TestProtocolTooOldIs426BeforeAuth(t *testing.T) {
	s := New(t)
	s.MinProtocol = 2
	err := client(s, "").Do(context.Background(), "POST", "/device/code", api.DeviceCodeRequest{}, nil)
	if !api.IsCode(err, "protocol_too_old") {
		t.Fatal(err)
	}
}

func TestRequestLogAndHook(t *testing.T) {
	s := New(t)
	var seen int
	s.OnRequest = func(*http.Request) { seen++ }
	_ = client(s, "").Do(context.Background(), "GET", "/me", nil, nil)
	reqs := s.Requests()
	if seen != 1 || len(reqs) != 1 || reqs[0].Method != "GET" || reqs[0].Path != "/api/cli/v1/me" {
		t.Fatalf("%d %+v", seen, reqs)
	}
}
