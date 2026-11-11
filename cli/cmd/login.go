package cmd

import (
	"context"
	"errors"
	"flag"
	"fmt"
	"io"
	"os"
	"runtime"
	"time"

	"github.com/geocodio/yak/cli/internal/api"
	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/keychain"
	"github.com/geocodio/yak/cli/internal/ui"
	"github.com/geocodio/yak/cli/internal/version"
)

func init() {
	commands["login"] = login
	commands["logout"] = logout
	commands["whoami"] = whoami
}

var errNotSignedIn = errors.New("not signed in")

func userAgent() string { return version.UserAgent(runtime.GOOS, runtime.GOARCH) }

func archName() string {
	if runtime.GOARCH == "amd64" {
		return "x86_64"
	}
	return runtime.GOARCH
}

func login(ctx *Context, args []string) int {
	fs := flag.NewFlagSet("login", flag.ContinueOnError)
	fs.SetOutput(io.Discard)
	urlFlag := fs.String("url", "", "")
	nameFlag := fs.String("name", "", "")
	if err := fs.Parse(args); err != nil {
		fmt.Fprintln(ctx.Stderr, "Usage: yak login [--url <address>] [--name <device name>]")
		return 2
	}
	cfg, err := config.Load(ctx.ConfigDir)
	if err != nil {
		ui.Fail(ctx.Stderr, "Could not read the yak config: %v", err)
		return 1
	}
	baseURL := firstNonEmpty(*urlFlag, ctx.Env("YAK_URL"), cfg.BaseURL)
	if baseURL == "" {
		baseURL, _ = ctx.Prompter.Edit("Yak address", "https://")
	}
	if baseURL == "" || baseURL == "https://" {
		ui.Fail(ctx.Stderr, "Which Yak? Run yak login --url <address>.")
		return 1
	}
	name := firstNonEmpty(*nameFlag, cfg.DeviceName)
	if name == "" {
		name, _ = os.Hostname()
	}

	c := api.New(baseURL, "", ctx.HTTP, userAgent())
	bg := context.Background()
	var code api.DeviceCode
	req := api.DeviceCodeRequest{Name: name, OS: runtime.GOOS, Arch: archName(), CLIVersion: version.Version}
	if err := c.Do(bg, "POST", "/device/code", req, &code); err != nil {
		return reportAPIError(ctx, err)
	}

	fmt.Fprintf(ctx.Stdout, "\n  Your code is %s\n", code.UserCode)
	fmt.Fprintf(ctx.Stdout, "  Opening %s in your browser...\n", code.VerificationURL)
	if ctx.OpenURL != nil {
		_ = ctx.OpenURL(code.VerificationURLComplete)
	}
	fmt.Fprintf(ctx.Stdout, "  No browser? Open that address yourself and type the code.\n\n")
	fmt.Fprintln(ctx.Stdout, "Waiting for you to approve it (ctrl-c to cancel)...")

	interval := time.Duration(max(code.Interval, 1)) * time.Second
	deadline := ctx.Now().Add(time.Duration(code.ExpiresIn) * time.Second)
	for {
		if !ctx.Now().Before(deadline) {
			return codeExpired(ctx)
		}
		ctx.Sleep(interval)
		var tok api.TokenResponse
		err := c.Do(bg, "POST", "/device/token", map[string]string{"device_code": code.DeviceCode}, &tok)
		switch {
		case err == nil:
			return finishLogin(ctx, cfg, baseURL, tok)
		case api.IsCode(err, "authorization_pending"):
		case api.IsCode(err, "slow_down"):
			interval += 5 * time.Second
		case api.IsCode(err, "access_denied"):
			ui.Fail(ctx.Stderr, "The code was denied, so nothing was saved.")
			fmt.Fprintln(ctx.Stderr, "  Run yak login again if that was a mistake.")
			return 1
		case api.IsCode(err, "expired_token"):
			return codeExpired(ctx)
		default:
			return reportAPIError(ctx, err)
		}
	}
}

func codeExpired(ctx *Context) int {
	ui.Fail(ctx.Stderr, "That code expired. Codes work for 10 minutes.")
	fmt.Fprintln(ctx.Stderr, "  Run yak login again for a new one.")
	return 1
}

func finishLogin(ctx *Context, cfg config.Config, baseURL string, tok api.TokenResponse) int {
	if err := ctx.Keychain.Set(baseURL, tok.Token); err != nil {
		ui.Fail(ctx.Stderr, "Could not save the token to the keychain: %v", err)
		return 1
	}
	cfg.BaseURL, cfg.DeviceName = baseURL, tok.Device.Name
	if err := cfg.Save(ctx.ConfigDir); err != nil {
		ui.Fail(ctx.Stderr, "Could not save the yak config: %v", err)
		return 1
	}
	ui.OK(ctx.Stdout, "Signed in as %s. Token saved to the keychain as \"%s\".", tok.User.Email, tok.Device.Name)
	return 0
}

// authedClient loads the config and token. It prints the hint itself when the person is not signed in.
func authedClient(ctx *Context) (*api.Client, config.Config, error) {
	cfg, err := config.Load(ctx.ConfigDir)
	if err != nil {
		ui.Fail(ctx.Stderr, "Could not read the yak config: %v", err)
		return nil, cfg, err
	}
	if cfg.BaseURL != "" {
		if token, err := ctx.Keychain.Get(cfg.BaseURL); err == nil {
			return api.New(cfg.BaseURL, token, ctx.HTTP, userAgent()), cfg, nil
		}
	}
	fmt.Fprintln(ctx.Stderr, "Run yak login.")
	return nil, cfg, errNotSignedIn
}

// reportAPIError prints the right copy for an API failure and returns exit code 1. It never touches the token.
func reportAPIError(ctx *Context, err error) int {
	switch {
	case api.IsCode(err, "device_revoked"):
		ui.Fail(ctx.Stderr, "This computer was removed from Yak.")
		fmt.Fprintln(ctx.Stderr, "  Someone revoked it in Settings, Devices. Nothing changed on Yak.")
		fmt.Fprintln(ctx.Stderr, "  To use this computer again, run yak login.")
		fmt.Fprintln(ctx.Stderr, "  If a session here is still read-only, clear it with yak marker clear <session-id>.")
	case api.IsCode(err, "invalid_token"):
		ui.Fail(ctx.Stderr, "Yak does not recognise this computer's token. Run yak login.")
	default:
		ui.Fail(ctx.Stderr, "%s", err.Error())
	}
	return 1
}

func logout(ctx *Context, _ []string) int {
	c, cfg, err := authedClient(ctx)
	if err != nil {
		if errors.Is(err, errNotSignedIn) {
			return 0
		}
		return 1
	}
	if err := c.Do(context.Background(), "DELETE", "/device", nil, nil); err != nil && !api.IsCode(err, "device_revoked") {
		return reportAPIError(ctx, err)
	}
	if err := ctx.Keychain.Delete(cfg.BaseURL); err != nil && !errors.Is(err, keychain.ErrNotFound) {
		ui.Fail(ctx.Stderr, "Could not remove the token from the keychain: %v", err)
		return 1
	}
	ui.OK(ctx.Stdout, "Signed out. This computer was removed from Yak.")
	return 0
}

func whoami(ctx *Context, _ []string) int {
	c, cfg, err := authedClient(ctx)
	if err != nil {
		return 1
	}
	var me api.Me
	if err := c.Do(context.Background(), "GET", "/me", nil, &me); err != nil {
		return reportAPIError(ctx, err)
	}
	fmt.Fprintf(ctx.Stdout, "User:   %s <%s>\nDevice: %s\nServer: %s\n", me.User.Name, me.User.Email, me.Device.Name, cfg.BaseURL)
	return 0
}

func firstNonEmpty(values ...string) string {
	for _, v := range values {
		if v != "" {
			return v
		}
	}
	return ""
}
