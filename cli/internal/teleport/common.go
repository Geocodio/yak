package teleport

import (
	"context"
	"errors"
	"fmt"
	"io"
	"runtime"
	"strings"
	"time"

	"github.com/geocodio/yak/cli/internal/api"
	"github.com/geocodio/yak/cli/internal/config"
	"github.com/geocodio/yak/cli/internal/gitx"
	"github.com/geocodio/yak/cli/internal/ui"
)

// Deps are the side-effecting dependencies of a teleport, replaced in tests.
type Deps struct {
	API       *api.Client
	Prompter  ui.Prompter
	Out       io.Writer
	Config    config.Config
	ConfigDir string
	Now       func() time.Time
	Sleep     func(time.Duration)
	Env       func(string) string
	Home      string
}

// AppliedRepo is one repo as reported back to Yak when a teleport down completes.
type AppliedRepo = api.AppliedRepo

// UserError is a failure whose message is already written for a person.
type UserError struct {
	Msg string
	Err error
}

func (e *UserError) Error() string { return e.Msg }
func (e *UserError) Unwrap() error { return e.Err }

func userErrorf(cause error, format string, a ...any) *UserError {
	return &UserError{Msg: fmt.Sprintf(format, a...), Err: cause}
}

func detail(e *api.Error, key string) string {
	if v, ok := e.Details[key]; ok {
		return fmt.Sprint(v)
	}
	return ""
}

// friendly turns the teleport-specific API errors into the copy a person should read. Other errors pass through
// untouched so the command can handle revoked devices and old protocol versions.
func friendly(err error) error {
	e, ok := api.AsError(err)
	if !ok {
		return err
	}
	switch e.Code {
	case "thread_owned_by_yak":
		return userErrorf(err, "This thread is on Yak. Run yak teleport %s to bring it down first.", detail(e, "thread_id"))
	case "owned_by_other_device":
		return userErrorf(err, "This thread is on another computer (%s). Teleport it up from there first.", detail(e, "device_name"))
	case "thread_owned_by_device":
		return userErrorf(err, "This thread is on %s. Teleport it up from there first.", detail(e, "device_name"))
	case "already_on_this_device":
		return userErrorf(err, "This thread is already on this computer.")
	case "branch_mismatch":
		return userErrorf(err, "This checkout is on %s, but the thread is on %s. Switch branches and run yak teleport again.", detail(e, "current_branch"), detail(e, "thread_branch"))
	case "teleport_in_progress":
		return userErrorf(err, "Another teleport is already running for this thread from %s. Wait for it or cancel it there.", detail(e, "device_name"))
	case "repo_not_on_yak":
		return userErrorf(err, "%s is not on Yak, so this thread cannot move.", detail(e, "repo"))
	case "wrong_repo":
		return userErrorf(err, "This checkout is %s, but the thread is in %s.", detail(e, "given_repo"), detail(e, "thread_repo"))
	case "forbidden":
		return userErrorf(err, "You can see this thread but not drive it.")
	case "thread_not_found":
		return userErrorf(err, "Yak does not know that thread.")
	case "teleport_expired":
		return userErrorf(err, "That teleport expired after %s without activity. Nothing changed. Run yak teleport again.", expiryPhrase(detail(e, "minutes")))
	case "session_incomplete":
		return userErrorf(err, "Yak did not receive the whole session. Nothing changed. Run yak teleport again.")
	}
	return err
}

// resumable turns an error that ends a teleport halfway into one that names the next step. Teleport-specific
// errors already read as advice, and auth and protocol errors keep their own handling in the command.
func resumable(err error) error {
	err = friendly(err)
	var userErr *UserError
	if errors.As(err, &userErr) {
		return err
	}
	if api.IsCode(err, "protocol_too_old") || api.IsCode(err, "device_revoked") || api.IsCode(err, "invalid_token") || isCancelled(err) {
		return err
	}
	return userErrorf(err, "%v. Run yak teleport again to resume.", strings.TrimRight(err.Error(), "."))
}

// expiryPhrase names the idle window the server reported, such as "45 minutes".
func expiryPhrase(minutes string) string {
	switch minutes {
	case "":
		return "a while"
	case "1":
		return "1 minute"
	}
	return minutes + " minutes"
}

func osArch() (string, string) {
	arch := runtime.GOARCH
	if arch == "amd64" {
		arch = "x86_64"
	}
	return runtime.GOOS, arch
}

func (d Deps) say(format string, a ...any) { fmt.Fprintf(d.Out, format+"\n", a...) }

// cancel tells Yak the teleport is off. It runs even when ctx is already cancelled and never fails the caller.
func (d Deps) cancel(ctx context.Context, teleportID, reason, detail string) {
	_ = d.API.Do(context.WithoutCancel(ctx), "POST", "/teleports/"+teleportID+"/cancel", api.CancelRequest{Reason: reason, Detail: detail}, nil)
}

// sleep waits for d through the injectable Sleep and returns early with ctx's error when ctx ends, so Ctrl-C
// does not wait out a poll interval.
func (d Deps) sleep(ctx context.Context, wait time.Duration) error {
	done := make(chan struct{})
	go func() {
		d.Sleep(wait)
		close(done)
	}()
	select {
	case <-done:
		return ctx.Err()
	case <-ctx.Done():
		return ctx.Err()
	}
}

const (
	gitNetworkAttempts = 3
	// maxPollFailures is how many polls in a row may fail on the network before the teleport stops waiting.
	maxPollFailures = 5
)

// gitNetwork runs a git command that talks to a remote, trying again after a failure with a growing pause.
// A cancelled ctx stops it at once.
func (d Deps) gitNetwork(ctx context.Context, repo gitx.Repo, args ...string) error {
	var err error
	for attempt := 1; attempt <= gitNetworkAttempts; attempt++ {
		if attempt > 1 {
			if serr := d.sleep(ctx, time.Duration(attempt-1)*2*time.Second); serr != nil {
				return serr
			}
		}
		if _, err = repo.RunNetwork(ctx, args...); err == nil || ctx.Err() != nil {
			return err
		}
	}
	return err
}

func isCancelled(err error) bool { return errors.Is(err, context.Canceled) }

// remoteFor returns the name of the remote whose fetch URL is url.
func remoteFor(repo gitx.Repo, url string) (string, error) {
	out, err := repo.Run("remote", "-v")
	if err != nil {
		return "", err
	}
	first := ""
	for _, line := range strings.Split(out, "\n") {
		f := strings.Fields(line)
		if len(f) < 3 || f[2] != "(fetch)" {
			continue
		}
		if first == "" {
			first = f[0]
		}
		if f[1] == url {
			return f[0], nil
		}
	}
	if first == "" {
		return "", errors.New("this checkout has no remote")
	}
	return first, nil
}

// resolveCheckout finds the first remote of the checkout that is on Yak.
func resolveCheckout(ctx context.Context, d Deps, repo gitx.Repo) (api.ResolveItem, bool, error) {
	remotes, err := repo.Remotes()
	if err != nil {
		return api.ResolveItem{}, false, err
	}
	if len(remotes) == 0 {
		return api.ResolveItem{}, false, nil
	}
	var res api.ResolveResult
	if err := d.API.Do(ctx, "POST", "/repos/resolve", api.ResolveRequest{Remotes: remotes}, &res); err != nil {
		return api.ResolveItem{}, false, err
	}
	for _, item := range res.Repos {
		if item.OnYak && item.Repo != nil {
			return item, true, nil
		}
	}
	return api.ResolveItem{}, false, nil
}
