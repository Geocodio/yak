# Teleport

Teleport moves a Claude Code session, and the git branch it works on, between your computer and a Yak thread. Use it to continue a Yak thread in your own terminal, or to hand a local session to Yak so it can keep working, open a PR and serve a preview.

A thread has one owner at a time:

- While a thread is on your computer, Yak runs no turns on it. Preview, terminal and [ssh](ssh.md) are unavailable. The dashboard shows the thread as it was when it left Yak.
- While a thread is on Yak, the local session is read-only. Claude Code on your computer refuses new prompts in it until you teleport the thread back or clear the marker (see [yak marker](cli.md#yak-marker)).

Only committed work travels, through GitHub. The session transcript travels through Yak.

## Install

Teleport needs the Yak plugin for Claude Code. The plugin carries the `yak` command.

In Yak, open a thread and choose **Teleport to computer**, or go to **Settings > Devices** (**Add a computer**). Copy the install command and run it in a terminal on your computer. It looks like this:

```bash
curl -fsSL https://yak.example.com/install | sh
```

The script:

1. Checks that `claude` is on your PATH. If it is not, it stops before it changes anything.
2. Adds the Yak plugin marketplace and installs the `yak` plugin in Claude Code.
3. Writes a small `yak` launcher to `~/.local/bin/yak`. It always runs the newest installed plugin version.
4. Starts the sign-in for this Yak (see [Sign in](#sign-in)).

If `~/.local/bin` is not on your PATH, the script tells you which line to add to your shell profile. The first time `yak` runs, the plugin downloads the program built for your platform from a GitHub release and checks its SHA-256 checksum.

### Install by hand

Run these in order, in any terminal where `claude` works:

```bash
claude plugin marketplace add Geocodio/yak
claude plugin install yak@yak
yak login --url https://yak.example.com
```

The marketplace is a GitHub repository. The operator can point Yak at another one with `YAK_TELEPORT_MARKETPLACE` (default `Geocodio/yak`); the install command on the dashboard always carries the right address. The plugin puts `yak` only on the PATH of Claude Code's own shell tool. For `yak` in your own terminal, use the install script, which writes the launcher at `~/.local/bin/yak`. The `/yak teleport` command inside a Claude Code session works either way.

Update the plugin later with `claude plugin update yak`. A Yak server can refuse an old plugin; the command then tells you to update.

Supported platforms: macOS on Apple silicon and Intel, and Linux on x86_64. There is no Windows build. On any other platform `yak` prints `yak does not support <os>/<arch>`. See [cli.md](cli.md#install) for where state is kept.

## Sign in

Pair each computer once. A paired computer holds a token in your operating system keychain and acts as you.

1. Run `yak login`. If it does not know your Yak address, it asks "Yak address". You can pass it as `yak login --url https://yak.example.com`.
2. The command prints a code like `ABCD-1234` and opens the approval page in your browser. If no browser opens, go to the address it prints, `/device`, and type the code.
3. On the page, check that the code matches your terminal and select "Approve this computer". Select "This is not me" if you did not start the login.
4. The terminal finishes by itself: `Signed in as <email>. Token saved to the keychain as "<computer name>".`

Codes work for 10 minutes. After that the page says "This code expired" and you run `yak login` again.

Paired computers are listed in Settings, Devices ("Paired computers"). "Revoke" on a computer stops its token at once and stops any teleport it has in flight. To use that computer again, run `yak login`. Check who you are signed in as with `yak whoami`.

## First teleport

The shortest way up and back:

1. In a checkout of a repo that is on Yak, start Claude Code and work as usual. Commit what you want to keep.
2. Run `/yak teleport` in the session. Yak takes the branch and the session, and the session becomes read-only. The output ends with a link to the thread.
3. Work with Claude in the thread page. When you want it back, open the thread, select "Teleport to computer" and run the command it shows (`yak teleport <thread>`) in your checkout of that repo.
4. Claude Code opens the same session with `claude --resume`. Yak runs no turns on the thread until you teleport it up again.

## Teleport to Yak

Run this from the Claude Code session you want to move, in a git checkout of a repo that is on Yak. Use the slash command, or run `yak teleport` in a terminal.

```
/yak teleport
/yak teleport "Fix the lint failure on the PR"
```

Text that does not start with a flag or a thread number becomes the first message Yak sends Claude. Without it, Claude waits for your next message.

What happens:

1. Yak finds the repo from your git remotes. If the repo is not on Yak you see "This repo is not on Yak, so the session cannot move. Add it to Yak first."
2. The command picks the session: `--session <id>`, else the newest session for this folder (it asks "Teleport the most recent session, ...?").
3. It prepares your working tree (see below), then pushes the branch to GitHub, uploads the session, and asks Yak to start the thread.
4. The output ends with "Yak has the thread. This session is now read-only." and "Continue at <thread url>".

If every sandbox slot is busy, the thread is on Yak and starts when one is free.

### What is carried

- The git branch, through GitHub.
- The Claude session: transcript, subagent transcripts, tool results and changed memory files.
- Your git name and email, so commits on the thread carry them.

The upload resumes after an interruption. If your connection drops, the command says "Run yak teleport again to resume it". If Yak's copy of the session differs from yours, nothing is overwritten and the teleport is cancelled.

### Uncommitted work

The command refuses to move a checkout in an unsafe state: a merge or rebase in progress, a detached HEAD, a branch with no commits, or a submodule with local changes. It lists what to fix.

Tracked files with changes are committed as a WIP commit so they travel. In a terminal it asks "Commit N changed tracked file(s) as a WIP commit so they travel with the thread?" and lets you edit the message (default `WIP: teleport to Yak`). Without a terminal, pass `--wip`; otherwise the teleport stops.

Untracked files are not carried unless you choose them. The command shows a picker titled "Untracked files to include in the WIP commit", or you pass `--add <path>` for each file.

If you are on the repo's default branch, the command never commits to it. It makes a detached WIP commit, and Yak assigns the branch `yak/<thread-title-slug>` (the slug is at most 50 characters). On any other branch, the thread uses your branch name.

### Files that look like secrets

Yak decides from file names only and never reads contents. These are left out and shown as "looks like a secret, not selected":

| Pattern | Examples |
| --- | --- |
| Env files | `.env`, `.env.local`, `*.env` |
| Keys and certificates | `*.pem`, `*.key`, `*.p12`, `*.pfx`, `*.jks`, `*.keystore`, `*.asc`, `*.gpg`, `id_rsa*`, `id_ed25519*`, anything under `.ssh/` or `.gnupg/` |
| Provider and tool credentials | `credentials*.json`, `service-account*.json`, `client_secret*.json`, `auth.json`, `.aws/credentials`, `.npmrc`, `.pypirc`, `.netrc`, `.git-credentials`, `.dockercfg`, `.pgpass`, `kubeconfig` |
| Infrastructure state | `*.tfvars`, `*.tfstate*`, `secrets.yml`, `secrets.yaml`, `secrets.json`, `vault-password*` |
| Databases and backups | `*.sqlite`, `*.sql.gz`, `*.kdbx`, `*.bak`, `*.orig` |
| Names that contain a word | `secret`, `credential`, `token`, `passwd`, `password`, `apikey`, `api_key` or `api-key`, in a file with no extension or a data extension such as `.json`, `.yml`, `.txt`, `.csv`, `.toml` |

Example and documentation files are never flagged: `*.example`, `*.sample`, `.env.template`, `.env.dist`, `*.md`, `*.pub`, `*.crt`, `*.cer`, `*.d.ts`.

To include them anyway, pass `--include-secrets` and confirm "Include files that look like secrets in the teleport? Their contents go to Yak." (default no). A modified tracked file that looks like a secret asks "<path>: this file looks like it holds secrets, include anyway?". If you decline, the teleport stops with "modified files that look like secrets were left out, so the tree is still dirty".

### Teleporting again

If the thread already exists and is on your computer, running `/yak teleport` in the same session sends it back up to the same thread. If your checkout is on a different branch than the thread, you see "This checkout is on X, but the thread is on Y. Switch branches and run yak teleport again."

## Teleport to your computer

1. Open the thread page, select "Teleport to computer", and copy the command, or select "Open in terminal" (see [the yak:// link](#the-yak-link)). The dialog says "Nothing changes until you run the command." Select "Done" to mark the thread as waiting for a computer.
2. In a terminal, go to your checkout of the thread's repo and run `yak teleport <thread>`. The command asks "Teleport thread N "title" (repo) to this computer?" and waits for a yes. `--yes` skips the question.
3. Yak commits any unsaved sandbox work as a WIP commit (`WIP: teleport to local machine`) and pushes the branch. Your computer fetches it and fast-forwards. It never merges or rebases.
4. The command downloads the session, checks each file by hash, and writes the files only if all of them arrive.
5. It then starts `claude --resume <session-id>` in the right folder. If it cannot start Claude, it prints `Continue with: cd <folder> && claude --resume <session-id>`.

The final line is "Thread N is now on this computer. Yak will not run turns on it until you teleport it back up."

If you run the command from a checkout of a different repo, it stops with a message that names the right repo.

### A turn is running

If Claude is in the middle of a turn, the command asks:

- "Wait for it to finish" (default). The thread moves on its own after the turn ends.
- "Stop it now". The running turn is cut off and work it has not saved is lost. The command asks again to confirm.
- "Cancel".

The dialog on the thread page offers the same choice with radio buttons, and the command skips the question when you pass `--wait` or `--stop`. While Yak waits, the thread page shows "<computer> is waiting for this turn to finish" with "Stop now and teleport" and "Cancel teleport".

### Visibility

On a thread the team can see, the command says that local turns add what the local agent reads to the transcript, and asks "Keep this thread visible to the team, or make it private?". The choices are "Keep it visible to the team" (default) and "Make it private". Use `--keep` or `--private` to answer in advance. See [threads.md](threads.md) for what private means.

### Your checkout has changes

If your checkout does not match the thread's last known state, the command lists the differences and asks "What should happen to your changes?":

| Choice | What it does |
| --- | --- |
| "Stash them, then apply the thread" | Runs `git stash`, applies the thread, and leaves the stash for you. Not offered when your local branch has commits the thread does not have. |
| "Apply into a new worktree" | Creates `<checkout>-yak-<thread-slug>` next to your checkout and applies the thread there. Your checkout stays as it is. |
| "Cancel" (default) | Changes nothing. Yak keeps the thread. |

Pass `--stash` or `--worktree` to choose in advance. If stashed changes conflict with the thread, the conflict markers are in the files and the stash is kept.

If the thread has file names your computer cannot hold, the command stops, lists the paths and tells you to ask Claude on Yak to rename or remove them.

### How the session resumes

The session is written to Claude Code's own session folder for the checkout, so `claude --resume <session-id>` finds it. If your local copy of the session had changed since the last teleport, it is saved as a backup first and the output names it. A memory file edited on both sides keeps your version and saves Yak's as a conflict copy.

The first time Claude Code resumes the session, the plugin adds a short environment note: which sandbox paths map to which folders on your computer, the files to refresh after pulling (such as dependencies), and any repos that stayed on Yak.

## Multi-repo teleport

A thread can work in several repos ([multi-repo.md](multi-repo.md)). When you teleport up, pass each extra directory with `--add-dir`:

```
yak teleport --add-dir ../acme-api --add-dir ../acme-docs
```

Each directory that is a repo on Yak is classified before anything is sent:

| Role | When | What Yak does |
| --- | --- | --- |
| Changes | Detached HEAD, uncommitted changes, commits not on GitHub, or a branch other than the default | Commits, branches and pushes it like the primary repo. Claude can change it. |
| Context | Nothing to carry | Clones it on Yak under `/extras/<name>`. Claude reads it only. |

The command prints each directory with its role and reason, then asks "Bring <repo> (<role>) to Yak?" for each. A directory you decline, or one that is not on Yak, is listed as "Not uploaded (not on Yak)" and does not travel.

On the way down:

- The primary repo is applied to your checkout as described above.
- Each extra repo with changes is cloned fresh into `<checkout>-<thread-slug>/<repo>`.
- Context repos stay on Yak. The output says "Context repos stay on Yak: ...".
- If an extra repo cannot be brought down, the command skips it and reports "Not applied: <repo> (<reason>)". The rest of the teleport still completes.

## Events while a thread is on your computer

Yak does not start turns on a thread your computer owns. Events that would normally start one are held instead: a CI failure, a review comment, or a Slack summon. They show on the thread page under "Held while on <computer>", and Yak tells the owner by its usual notification routes. A held event older than 24 hours is tinted (`YAK_TELEPORT_HELD_EVENT_TINT_HOURS`).

Questions from a sub-thread whose stack root is on your computer are held too. The plugin also prints unseen messages from the stack when you resume or send a prompt in the local session.

When you teleport up from the dashboard command, the dialog has a checkbox "Send the held events to Claude as the first message (N waiting)". Fix them locally, or let Claude handle them after the thread is back on Yak.

## Which Claude account runs turns

| Where | Account |
| --- | --- |
| Thread on Yak | The thread's fixed Claude account. See [claude-accounts.md](claude-accounts.md). |
| Thread on your computer | Your local Claude Code login. |

The command tells you both ways: "Yak runs it under the <account> Claude account; turns you run here use your local Claude login." and "Turns you run here use your local Claude login, not the <account> account."

## Expiry

A teleport that sees no activity for 30 minutes expires and changes nothing. The operator sets the limit with `YAK_TELEPORT_EXPIRY_MINUTES` (config key `yak.teleport.expiry_minutes`, default 30). The command's message always says 30 minutes: "That teleport expired after 30 minutes without activity. Nothing changed. Run yak teleport again." Waiting for a free sandbox slot after a teleport up lasts up to 20 minutes by default (`YAK_TELEPORT_SLOT_WAIT_MINUTES`).

## The yak:// link

The "Open in terminal" button in the "Teleport to computer" dialog opens `yak://teleport/<thread>`. The link handler needs the plugin and a one-time registration:

```
yak open-link --install
```

On macOS this creates a small app named "Yak Link" in `~/Applications`. On Linux it registers a `.desktop` entry through `xdg-mime`. When you open a link, the handler finds your checkout of the thread's repo in `~/code`, `~/projects` and `~/src`. If it finds none, it asks "Where is <repo> on this computer?" once and remembers the answer. Then it opens a terminal that runs the teleport. If registration fails, use the command shown on the thread page. See [yak open-link](cli.md#yak-open-link).

## Troubleshooting

| Message | What to do |
| --- | --- |
| `Run yak login.` | This computer is not signed in. Run `yak login`. |
| `This computer was removed from Yak.` | Someone revoked it in Settings, Devices. Run `yak login`. If a session is still read-only, run `yak marker clear <session-id>`. |
| `Yak does not recognise this computer's token. Run yak login.` | The token is stale. Run `yak login`. |
| `The Yak plugin is not installed.` | The launcher found no plugin version. Run `claude plugin install yak@yak`. |
| `This version of yak is too old for Yak.` | Run `claude plugin update yak`, then repeat the command. |
| `yak does not support <os>/<arch>` | There is no build for this platform. Use macOS or Linux x86_64. |
| `Claude Code is not installed.` | The install script stops when `claude` is not on the PATH. Install Claude Code, open a new terminal and run the install command again. |
| `yak: command not found` | Add `~/.local/bin` to your PATH, for example `export PATH="$HOME/.local/bin:$PATH"` in your shell profile, then open a new terminal. If `~/.local/bin/yak` does not exist, run the install command again. |
| `That code expired. Codes work for 10 minutes.` | Run `yak login` again. |
| `The code was denied, so nothing was saved.` | The approval page said "This is not me". Run `yak login` again if that was a mistake. |
| `This repo is not on Yak, so the session cannot move.` | Add the repo to Yak first ([repositories.md](repositories.md)). |
| `No Claude session found for this folder. Pass --session <id>.` | Run it from the folder the session started in, or pass the id. |
| `a merge is in progress, finish or abort it first` (or rebase) | Finish or abort the operation, then retry. |
| `HEAD is detached, switch to a branch first` | Check out a branch. |
| `uncommitted changes to tracked files, commit them or pass --wip` | Commit, or pass `--wip`. |
| `files that look like secrets need --include-secrets` | Remove the file from `--add`, or pass `--include-secrets`. |
| `Could not push <branch> to <repo>. Nothing was uploaded and Yak did not take the thread` | Check that you can push to the repo on GitHub, then retry. |
| `Yak still cannot see the pushed branch on GitHub.` | Check your access to the repo and retry. |
| `Yak's copy of this session differs from the one on this computer, so nothing was overwritten. The teleport is cancelled.` | The thread moved on Yak since your last teleport. Teleport it down first with `yak teleport <thread>`. |
| `The upload was interrupted ... Run yak teleport again to resume it.` | Run the same command again. |
| `This thread is on Yak. Run yak teleport <N> to bring it down first.` | The thread is not on your computer. Use the number shown. |
| `This thread is on another computer (<name>). Teleport it up from there first.` | Go to that computer, or revoke it in Settings, Devices. |
| `This checkout is on <a>, but the thread is on <b>.` | Switch branches. |
| `Another teleport is already running for this thread from <computer>.` | Wait for it, or cancel it on that computer. |
| `Thread N is in <repo>, but this checkout is not.` | Run the command from a checkout of the named repo. |
| `You can see this thread but not drive it.` | You need driving rights to teleport a thread. Ask the owner. |
| `Yak does not know that thread.` | Check the thread number. |
| `This computer cannot hold some of the thread's files.` | Ask Claude on Yak to rename or remove the listed paths, then retry. |
| `Yak took too long to prepare the thread. Run yak teleport again.` | Retry. Yak keeps the thread. |
| `That teleport expired after 30 minutes without activity.` | Run the command again. |
| `Yak did not receive the whole session. Nothing changed.` | Run the command again. |
| `This session is on Yak. Run yak teleport <N> to bring it back, or run yak marker clear <id> ...` | Shown when you prompt a read-only session. Teleport the thread down, or clear the marker to use the session locally. |
| `Could not register the yak:// link. Use the command shown on the thread page.` | Run the command from the dialog instead of the link. |

If a teleport fails after Yak accepted it, the thread page shows "Teleport failed." with the reason and a "Retry" button.
||||||| e0a46b66
