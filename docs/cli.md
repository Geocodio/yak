# yak CLI

The `yak` command signs your computer in to Yak, teleports Claude Code sessions, opens shells in thread sandboxes and handles `yak://` links. This page is the reference for the Go `yak` command that runs on your own computer. It is different from the server-side `php artisan` commands that operators run, which are in [operations.md](operations.md). For the walkthrough, read [teleport.md](teleport.md) and [ssh.md](ssh.md).

## Install

`yak` comes with the Claude Code plugin for Yak. The plugin is published in a GitHub marketplace (`Geocodio/yak` by default, set by `YAK_TELEPORT_MARKETPLACE`). Run the install command from Settings > Devices, or by hand:

```
claude plugin marketplace add Geocodio/yak
claude plugin install yak@yak
claude plugin update yak
```

The install script described in [teleport.md](teleport.md#install) also puts a `yak` launcher at `~/.local/bin/yak`, so `yak` works in your own terminal and not only in Claude Code.

The plugin carries one binary per platform: macOS on Apple silicon (`darwin-arm64`), macOS on Intel (`darwin-x86_64`) and Linux on x86_64 (`linux-x86_64`). A small shell script picks the right one. On any other platform it prints `yak does not support <os>/<arch>`. There is no Windows build.

`yak version` prints the installed version, for example `yak cli-v0.1.0`.

## Where state lives

| What | Where |
| --- | --- |
| State folder | `$YAK_HOME`, else `$XDG_CONFIG_HOME/yak`, else `~/.config/yak` |
| `config.json` | Yak address (`base_url`), computer name (`device_name`) and the checkout folder for each repo (`checkouts`). Mode 0600 in a 0700 folder. |
| `markers/<session-id>.json` | Marks a Claude session as owned by Yak or by this computer. A session owned by Yak is read-only. |
| `seen/` | The last sub-thread message the plugin showed for each session. |
| Environment notes | One short note per session, shown once when Claude Code resumes it. |
| Token | The operating system keychain, one entry per Yak address, service name `yak-cli`. On macOS it is the login Keychain (through the `security` tool). On Linux it is the secret service (through `secret-tool`, which must be installed). |

## Environment variables

| Variable | Effect |
| --- | --- |
| `YAK_HOME` | State folder. Overrides the default. |
| `XDG_CONFIG_HOME` | Parent of the `yak` state folder when `YAK_HOME` is not set. |
| `YAK_URL` | Yak address for `yak login`. A `--url` flag wins over it; it wins over the saved address. |
| `CLAUDE_SESSION_ID` | Session that `yak teleport` moves when `--session` is not given. |

## Errors any command can show

| Message | Meaning and fix |
| --- | --- |
| `Run yak login.` | Not signed in on this computer. |
| `Yak does not recognise this computer's token. Run yak login.` | The token no longer matches. |
| `This computer was removed from Yak.` | Revoked in Settings, Devices. Run `yak login`. A session that is still read-only needs `yak marker clear <session-id>`. |
| `This version of yak is too old for Yak.` | Run `claude plugin update yak`. |

Exit code 0 is success, 1 is failure and 2 is a usage error, unless a command lists others.

## yak login

```
yak login [--url <address>] [--name <device name>]
```

Pairs this computer with Yak using a device code.

| Flag | Default | Meaning |
| --- | --- | --- |
| `--url <address>` | `YAK_URL`, then the saved address, then a prompt "Yak address" | The Yak server, for example `https://yak.example.com`. |
| `--name <device name>` | The saved name, else the computer's hostname | The name shown in Settings, Devices. |

It prints `Your code is XXXX-XXXX`, opens the approval page in your browser and waits (ctrl-c cancels). It checks every 5 seconds. Codes last 10 minutes.

| Output | Exit |
| --- | --- |
| `Signed in as <email>. Token saved to the keychain as "<name>".` | 0 |
| `The code was denied, so nothing was saved.` | 1 |
| `That code expired. Codes work for 10 minutes.` | 1 |
| `Which Yak? Run yak login --url <address>.` | 1 |
| `Could not save the token to the keychain: ...` | 1 |

## yak logout

```
yak logout
```

Revokes this computer on the server and deletes its keychain entry. Prints `Signed out. This computer was removed from Yak.` Exits 0 when you were not signed in.

## yak whoami

```
yak whoami
```

Prints three lines:

```
User:   Ada <ada@example.com>
Device: ada-laptop
Server: https://yak.example.com
```

## yak teleport

```
yak teleport [<thread>] [options]
```

With no thread number, it moves the current Claude session up to Yak. With a thread number, it brings that thread down to this checkout. A thread id is the 8-character id in the thread URL, such as `k7m2x9qd`. Flags can come before or after the number. The full behavior is in [teleport.md](teleport.md).

Up flags:

| Flag | Meaning |
| --- | --- |
| `--session <id>` | Session to move. Default: `CLAUDE_SESSION_ID`, else the newest session for this folder (it asks to confirm). |
| `--message <text>`, `-m <text>` | First message Yak sends Claude after the teleport. |
| `--add-dir <path>` | Extra repo directory to bring along. Repeatable. See [multi-repo teleport](teleport.md#multi-repo-teleport). |
| `--wip` | Commit changes to tracked files as a WIP commit without asking. |
| `--add <path>` | Untracked file to include in the WIP commit. Repeatable. |
| `--include-secrets` | Allow files that look like secrets. Still asks for confirmation. |

Down flags:

| Flag | Meaning |
| --- | --- |
| `--wait` | If a turn is running, wait for it to finish. |
| `--stop` | If a turn is running, stop it now. Cannot be combined with `--wait`. |
| `--keep` | Keep the thread visible to the team. |
| `--private` | Make the thread private. Cannot be combined with `--keep`. |
| `--stash` | If your checkout has changes, stash them and apply the thread. |
| `--worktree` | If your checkout has changes, apply the thread into a new worktree. Cannot be combined with `--stash`. |

Both:

| Flag | Meaning |
| --- | --- |
| `--yes` | Answer every question with its default and never prompt. |

Exit codes:

| Code | Meaning |
| --- | --- |
| 0 | Done. |
| 1 | Failed, or you cancelled ("Cancelled. Nothing changed."). |
| 2 | Usage error: more than one thread, a bad thread number, or flags that cannot be combined ("Those options cannot be combined."). |
| 3 | Refused before anything was sent, such as a detached HEAD, a merge in progress, a file that looks like a secret or a checkout of the wrong repo. |

Messages are listed in the [teleport troubleshooting table](teleport.md#troubleshooting).

## yak ssh

```
yak ssh [--config] <thread>
```

Opens a shell in the thread's sandbox through Yak's jump host. See [ssh.md](ssh.md).

| Flag | Meaning |
| --- | --- |
| `--config` | Print an `~/.ssh/config` entry instead of connecting. |

Exit code: the exit code of `ssh`, 1 on a Yak error, 2 on a usage error (`Usage: yak ssh [--config] <thread>`).

Error messages:

| Message | Fix |
| --- | --- |
| `Teleport it up first, preview, terminal and ssh are unavailable for a thread on your computer.` | Run `/yak teleport` in the session. |
| `The sandbox was destroyed. Restore it from the thread page: <url>` | Open the thread and restore the sandbox. |
| `This thread is closed, its branch and PR carry the result.` | Nothing to open. |
| `You need driving rights on this thread to open a shell.` | Ask the thread owner. |
| `Yak has no SSH key for you yet. Add one at <url>` | Add a key in Settings, SSH keys. |
| `yak ssh is not available on this Yak server yet.` | The server does not support it. |
| `SSH access is not set up on this Yak.` | The operator has not configured the jump host. |
| `This thread has no sandbox yet.` | Wait until the thread has started. |

## yak marker

```
yak marker show <session-id>
yak marker clear <session-id>
```

A marker is the file that makes a session read-only on this computer while Yak owns the thread. `show` prints it as JSON, or `No marker for that session.` `clear` removes it and prints `Cleared. This session is no longer read-only.` Use `clear` after a revoked computer, or to keep using a session locally. The session can then be teleported up again as a new thread. `<session-id>` is the UUID of the Claude session.

## yak open-link

```
yak open-link <yak://teleport/<thread>>
yak open-link --install
```

`--install` registers the `yak://` link handler: a small app named "Yak Link" on macOS, a `.desktop` entry on Linux. It prints `yak:// links now open a terminal on this computer.` If registration fails it prints `Could not register the yak:// link. Use the command shown on the thread page.` and exits 0. The handler is registered again on session start if the `yak` binary moved.

Given a link, it finds your checkout of the thread's repo (remembered in `config.json`, else looked up in `~/code`, `~/projects` and `~/src`, else it asks "Where is <repo> on this computer?") and opens a terminal that runs `yak teleport <thread> --from-link`. A bad link prints `That is not a Yak teleport link. Nothing was opened.`

## yak version

```
yak version
```

Prints `yak <version>`.

## Internal commands

The plugin calls these. You do not run them yourself.

| Command | Used by |
| --- | --- |
| `yak hook session-start`, `yak hook user-prompt-submit` | The plugin's hooks. |
| `yak mcp` | The plugin's MCP server named `yak`. |
| `yak slash-teleport` | The `/yak` slash command. |

## The /yak slash command

Inside Claude Code the plugin adds `/yak teleport`, described as "Teleport this session to Yak". It runs `yak teleport` for the current session and shows the output.

| Form | Meaning |
| --- | --- |
| `/yak teleport` | Move this session to Yak. |
| `/yak teleport "Fix the lint failure on the PR"` | Move it and send this as the first message. Text that does not start with a flag or a thread number is the message. |
| `/yak teleport <thread>` | Bring a thread down to this checkout. |
| `/yak teleport --add-dir ../acme-api --wip` | Any `yak teleport` flag works after the word `teleport`. |

Any other word after `/yak` shows `Could not read that command: unknown subcommand ... Usage: /yak teleport [<thread>] ["message"] [options]`.

## What the plugin hooks do

- On resume of a session, the plugin shows the environment note for a thread you just teleported down, once. If the thread is on your computer, it also shows unseen messages from its sub-threads, as `Message from Yak, <title> (<kind>)`.
- When you send a prompt in a session whose thread is on Yak, the plugin blocks it with `This session is on Yak. Run yak teleport <N> to bring it back, or run yak marker clear <id> to use it as a local session again (its work can be teleported up as a new thread).`
- Sessions with no marker are untouched. The hooks make no network call without a marker, wait at most 3 seconds for Yak, and never fail a prompt.
- The `yak` MCP server has one tool, `spawn_thread`. It always refuses with "Teleport this session to Yak first. Sub-threads run on Yak. Run /yak teleport, then ask again."
