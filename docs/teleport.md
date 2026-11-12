# Teleport

Teleport moves a Yak thread between Yak and Claude Code on your own computer. Yak opens PRs for human review and serves branch previews. Teleport changes where the conversation runs, nothing more.

## What Teleport Does

- **Teleport to computer** brings a thread that runs on Yak down to a checkout on your computer. You continue it in Claude Code.
- **Teleport to Yak** sends a Claude Code session from your computer up to Yak. Yak keeps working on it, and you can follow it in the dashboard.

Only committed work travels, through GitHub. Your computer fetches and fast-forwards. It never merges or rebases. If your checkout has changes, you choose what happens to them first.

Supported platforms: macOS (Apple silicon and Intel) and Linux on x86_64. Other systems are not supported. You need Claude Code on the computer.

## Install

In Yak, open a thread and choose **Teleport to computer**, or go to **Settings > Devices**. Copy the install command and run it in a terminal on your computer. It looks like this:

```bash
curl -fsSL https://{your-domain}/install | sh
```

The script:

1. Checks that `claude` is on your PATH. If it is not, the script stops before it changes anything.
2. Adds the Yak plugin marketplace and installs the `yak` plugin in Claude Code.
3. Writes a small `yak` launcher to `~/.local/bin/yak`. It always runs the newest installed plugin version.
4. Starts the sign-in for this Yak.

If `~/.local/bin` is not on your PATH, the script tells you which line to add to your shell profile.

The first time `yak` runs, the plugin downloads the program built for your platform from a GitHub release and checks its SHA-256 checksum.

## Install By Hand

Run these in order, in any terminal where `claude` works:

```bash
claude plugin marketplace add Geocodio/yak
claude plugin install yak@yak
yak login --url https://{your-domain}
```

The plugin only puts `yak` on the PATH of Claude Code's own shell tool. For `yak` in your terminal, use the install script, which writes the launcher at `~/.local/bin/yak`. The `/yak teleport` command inside a Claude Code session works either way.

The marketplace name comes from `YAK_TELEPORT_MARKETPLACE`. It defaults to `Geocodio/yak`.

## Sign In

`yak login` asks Yak for a short code, prints it, and opens a page on your Yak in your browser.

1. Check that the code on the page matches the one in your terminal.
2. Choose **Approve this computer**. Choose **This is not me** if you did not run `yak login`.
3. Go back to your terminal. `yak login` finishes by itself.

Codes work for 10 minutes. If the browser does not open, open the address that `yak login` prints and type the code. The token goes to your keychain, and the computer appears in **Settings > Devices** under its hostname. Use `--name` to pick a different name.

`yak whoami` shows who you are signed in as. `yak logout` removes the token and removes the computer from Yak.

## First Teleport

**To your computer.** Open a thread, choose **Teleport to computer**, and run the command it shows from inside your checkout of the repository:

```bash
yak teleport <thread-id>
```

You can also choose **Open in terminal**. The first time, `yak` asks where the repository lives on your computer and remembers the answer. If Claude is in the middle of a turn, you choose to wait for it or to stop it. Stopping keeps the work done so far. When it finishes, `yak` starts `claude --resume` in your checkout.

**To Yak.** In the Claude Code session, run:

```
/yak teleport
```

Add a message to start a turn right away: `/yak teleport "Fix the lint failure on the PR"`. The command commits your work first (it asks about anything uncommitted), pushes the branch and uploads the conversation. After that, the session on your computer is read-only and Yak waits for your next message.

## Revoking A Computer

Go to **Settings > Devices** and choose **Revoke** on the computer. Its token stops working at once. A teleport in progress there stops with a message that says so. If the thread was on that computer, it returns to Yak as it was when it left. Work done on the computer since then stays in that Claude Code session, which becomes a normal local session again. To bring that work to Yak, pair the computer again and run `yak teleport` to start a new thread from the branch.

To pair the computer again, run `yak login` on it. If a session there is still read-only, clear it with `yak marker clear <session-id>`.

A computer that has not been used for a while shows a warning in the list. Revoke it if the computer is gone.

## Troubleshooting

**`claude` not found.** The install script stops with "Claude Code is not installed". Install Claude Code, open a new terminal, and run the install command again.

**`yak` not on PATH.** Add `~/.local/bin` to your PATH, for example `export PATH="$HOME/.local/bin:$PATH"` in your shell profile, then open a new terminal. If `~/.local/bin/yak` does not exist, run the install command again.

**"The Yak plugin is not installed."** The launcher found no plugin version. Run `claude plugin install yak@yak`.

**The code expired.** Codes work for 10 minutes. Run `yak login` again for a new one. On the approval page, choose **Enter a new code**.

**This computer was removed from Yak.** Someone revoked it in Settings > Devices. Nothing changed on Yak. Run `yak login` to use the computer again.

**This version of yak is too old for Yak.** The plugin is older than this Yak supports. Nothing was changed. Run `claude plugin update yak`, then run the command again.

**The download or checksum failed.** On first run, `yak` downloads its program from the plugin's GitHub release. If the download fails, check that the computer can reach `github.com` and run `yak` again. If the checksum does not match, `yak` discards the file and stops. Run it again. If it keeps failing, update the plugin with `claude plugin update yak`.

**"yak does not support ..."** Teleport runs on macOS (arm64 and x86_64) and Linux (x86_64) only. Linux on arm64 is not supported.
