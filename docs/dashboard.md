# Dashboard pages

A short tour of the dashboard pages that other docs do not explain. Threads have their own page: see [Threads](threads.md). Every signed-in user can open every page below except People. The only admin-only controls are the Yak accounts, a repository's unattended account and the Awake share cards, which are limited to Yak admins (see [Claude accounts](claude-accounts.md)).

The icon rail on the left has four sections: Threads, Operations, Insights and Settings.

## Home

The Yak logo and `/` open home. When you can see at least one thread, home opens the most recently active one. When you can see none, home shows a Welcome page: a checklist (Add a repository, Add a Claude account, and an optional Connect a channel) and, once the first two are done, the new thread form. A slim setup bar above the pages says what is left for you: connect your own Claude account, set up [Teleport](teleport.md), replace a token that stopped working, or (for admins) add the shared Yak account. You can dismiss the neutral reminders.

## Command palette and keyboard shortcuts

The command palette runs actions, jumps to pages and finds threads, repositories and pull requests from one input.

### Open it

- Press `Cmd+K` on a Mac or `Ctrl+K` elsewhere. Press it again to close the palette.
- Select the search icon button beside **New thread** at the top of the thread list in the sidebar.
- On a phone, open the navigation drawer. The Threads tab has the same search icon button beside **New thread**.

### The empty palette

With nothing typed, the palette lists every action that can run now, in groups. The group for the page you are on comes first (for example **This thread**), then **Create**, **Go to**, **Settings** and **Help**. Threads, repositories and pull requests do not appear until you type.

### What typing finds

- **Actions and pages**, by label and by keyword. Typing `terminal` finds **Show terminal**. Typing `ssh` finds the SSH keys page.
- **Threads**, open and closed, sub-threads included, by title, repository or branch.
- **Repositories**, by name.
- **Pull requests**, by exact number (with or without `#`, so `#812` and `812` both work), by thread title or by repository.

Thread, repository and pull request search starts at two characters. You only see threads you are allowed to see, so a private thread of someone else never appears (see [Private threads](threads.md#sharing-and-private-threads)).

Ranking remembers what you pick. An action or result you chose before moves up among equal matches. The memory lives in this browser only. It is not shared with other browsers or people.

### Results

- `Enter` opens a thread or a repository. On a pull request it opens the pull request on GitHub in a new window.
- `Tab`, or the **More** button on the row, lists the actions of that result. For a thread these include opening the preview, opening the pull request and copying the branch name or link. For a repository they include **New thread in this repository** and **Open on GitHub**. Hotkeys do not apply to these actions.
- `Backspace` on an empty input goes back one step.
- `Esc` closes the palette.

A repository that is turned off still appears in search. It does not offer **New thread in this repository**, because the new thread dialog lists only active repositories.

### Start a thread from the palette

Type `new`, `create`, `start` or `spawn` followed by text, for example `new fix the login redirect on website`. **New thread** runs first, and `Enter` opens the New thread dialog with that text filled in as the description.

### Actions that cannot run now

An action that cannot run now stays hidden while the input is empty. Once you type something that matches it, the palette shows it disabled, with the reason. For example, **Next thread that needs you** says "Nothing needs you" when no thread waits on you, and **Show changes** says "This thread has no changes yet".

### Actions for the current page

Each page adds its own group. Actions marked "asks first" open a confirmation dialog above the palette. Nothing runs until you confirm. Cancel returns you to the palette.

| Page | Actions |
| --- | --- |
| A thread | **Show files**, **Show terminal**, **Show preview panel**, **Show changes**, **Show conversation**, **Toggle the overview**, **Go to the parent thread** (on a sub-thread), **Open file** (a step that searches the repository and opens the file in the Files panel), **Open preview in a new window**, **Open pull request on GitHub**, **Copy branch name**, **Copy thread link**, **Open a pull request**, **Star thread** or **Remove star**, **Close thread**, **Delete thread**, **Stop the turn**, **Continue the turn**, **Focus the composer**, **Filter changed files** (when the changes list is open), **Add a sub-thread**, **Teleport to your computer** or **Teleport to Yak** |
| A thread you own | **Share**, which opens the Share dialog, and **Make private (only me)** on a thread the team can see |
| A thread with sub-threads that failed | **Take over a sub-thread**, **Split a sub-thread**, **Drop a sub-thread**. Each picks the sub-thread first |
| A repository | **New thread in this repository**, **Open on GitHub**, **Open the risk profile** |
| Repositories | **Add repository** |
| A PR review | **Open the pull request on GitHub**, **Re-run this review** |
| A deployment | **Open the preview**, **Destroy the preview** |
| SSH keys | **Add SSH key** |
| Claude accounts | **Add Claude account**, and **Add Yak account** for admins |

These ask first: **Make private (only me)**, **Close thread**, **Delete thread**, **Stop the turn**, **Take over**, **Split** and **Drop** on a sub-thread, and **Destroy the preview**. **Open a pull request** asks first when the thread is private, because the pull request would be visible on GitHub (see [Private threads](threads.md#sharing-and-private-threads)).

Some thread actions follow the state of the thread. **Open a pull request** appears when you can drive the thread, it is open, it has changes and it has no pull request yet. It is disabled with a reason while the pull request is being opened ("The pull request is being opened") or waits for a co-owner ("Waiting for a co-owner"). **Stop the turn** says "No turn is running" when the thread is idle. **Open file** shows "Files are not available right now" when the sandbox is asleep and cannot list files.

### Who can see a thread

The palette can make a thread private, with **Make private (only me)**. It has no action that widens access. To let more people see a thread, use **Share**, which opens the Share dialog, and choose who there.

### Keyboard shortcuts

Hotkeys do not fire while you type in a field, or while a dialog or menu is open. Press `?` to open the full list in a dialog.

Keys written as `G H` are sequences: press `G`, then `H`. The second key must follow within one second, or the sequence starts over.

| Group | Keys | Action |
| --- | --- | --- |
| General | `Cmd+K` / `Ctrl+K` | Open the command palette |
| General | `?` | Keyboard shortcuts |
| General | `C` | New thread |
| General | `[` | Toggle the sidebar |
| General | `G H` | Go to Home |
| General | `G N` | Next thread that needs you |
| Thread | `G P` | Open preview in a new window |
| Thread | `G F` | Show files |
| Thread | `G T` | Show terminal |
| Thread | `G C` | Show conversation |
| Thread | `G R` | Open pull request on GitHub |
| Changes | `/` | Filter changed files |

Keys inside the open palette:

| Keys | Action |
| --- | --- |
| `Enter` | Run the selected action |
| `Tab` | More actions for a result |
| `Backspace` | Go back one step |
| `Esc` | Close |

The Thread keys act on the thread you are viewing. They do nothing on other pages, and the row of an action that cannot run now is skipped.

## Task log

The task log (Operations > Task log) is the list of everything that channels and the dashboard asked Yak to do. It is a log: you start work from the thread list, not from here. Each task runs as a turn in a thread (see [Concepts](concepts.md)). Open it from Operations or the command palette. Three tabs split the list: Tasks, PR Reviews and Setup, each with a count. Filter by status, source, repository or pull request, search, and sort by newest, oldest, source, author or repository. A "Needs co-owner" chip lists tasks waiting for a second person to approve. Open a task to see its logs, runs, cost and pull request, and to retry or cancel it.

## Operations

Operations holds the task log, Repositories, Deployments, PR reviews and Observations.

### Repositories

The repositories Yak can work on, with slug, name, CI system, setup state, base branch, status, default marker, task counts (total and last 7 days) and whether PR review is on. Choose **Add repository** to add one. Open a repository to edit its settings, set your Claude account for it, re-run setup or turn it off. See [Repositories](repositories.md) and [Repository config](repo-config.md).

### Deployments

Every branch preview: repository, branch, status, last accessed and preview URL. Filter by Active, Running, Hibernated, Failed or All. Open one to see its logs, hibernation settings and share link, and to rebuild or delete it. See [Previews](previews.md#branch-deployments).

### PR reviews

Feedback on the reviews Yak writes on pull requests. View all comments or group them by reviewer, and filter by repository, severity, scope, category, reviewer or reactions. It shows how people rated each comment. Open a pull request to see its review and **Re-run review**. See [PR review](pr-review.md).

### Observations

A log of what Yak noticed during scans and what it decided to do, including the times it took no action. Each row has the time, outcome (Acted or Took no action), source, repository and a short description, with a link to the task or the Sentry issue or commit behind it. Filter by repository and outcome. The page refreshes every 30 seconds.

## Insights

### Analytics

Charts of how Yak performs over the last 7, 30 or 90 days, filtered by repository and source: funnel, throughput, time to PR, where the time goes, slowest runs, failures, runs by kind, tool usage, feature usage, webhooks, review quality and an event explorer. If the operator turned telemetry off (`YAK_TELEMETRY_ENABLED=false`), a notice says so and most charts stay empty.

### Costs

Spend by day, week or month, filtered by repository and source. Claude Code figures are estimates at API list price, not a bill, because subscription accounts cover them. A separate "API-billed spend" card shows real Anthropic API usage by Yak itself (for example repository routing and notification copy). The page also shows tasks, average cost and duration, success and clarification rates, PR merge rate, and walkthrough video renders.

### Health

Live checks of everything Yak depends on, in two groups: System (queue worker, Redis, disk, sandbox host, Claude accounts, repositories, webhook signatures, video render, telemetry) and Channels (one check per channel). Checks run against the real dependency, so a full pass takes a few seconds. Choose **Refresh all** to rerun them, or refresh one row. Failing rows link to the matching docs.

## Settings

Settings has three groups: Account, Agent and Workspace.

### Account

- **Profile**: your name and email address, the Slack direct messages switch, "Email to Yak" addresses, your linked GitHub account and account deletion. See [Notifications](notifications.md).
- **GitHub account** (a card on the Profile page): connect your GitHub account so Yak can check your write access when you approve a change that needs a co-owner. Yak reads only your GitHub username and never acts on GitHub as you.
- **Claude accounts**: the accounts Yak runs your threads on. See [Claude accounts](claude-accounts.md).
- **Devices**: the computers you paired with `yak login`, with a **Revoke computer** action for each, and an install command to add another one. See [Teleport](teleport.md).
- **Notifications**: push on and off, the four toggles and your devices. See [Notifications](notifications.md).
- **SSH keys**: the keys that can open a shell in the sandboxes of threads you drive. Yak trusts the public keys of your linked GitHub account and you can add extra keys. See [SSH](ssh.md).

### Agent

- **Prompts**: the prompt templates Yak builds sessions from. Pick one, edit it, preview it against sample data, view saved versions and reset it to the shipped default. See [Prompting](prompting.md).
- **Skills**: Claude Code plugins installed for Yak sessions. Filter by All, Installed, Bundled or Available, search, install from a URL or path, update or uninstall, and add or refresh plugin marketplaces.
- **MCP servers**: tool servers available to Yak sessions, with their source, status (Connected, Needs login, Pending approval, Failed) and login. **Add MCP server** takes a name, a transport (Command, HTTP or SSE) and the command, URL and headers. Servers that come from a plugin or the deploy config cannot be removed here.

### Workspace

- **Linear**: connect Yak to your Linear workspace so comments and issue updates post as the Yak app. Shows the workspace and when the access token expires, and lets you connect, reconnect or disconnect. See [Channels](channels.md).
- **Video walkthroughs**: how the walkthrough attached to each PR looks: fonts, logo, chapter card and, when `ELEVENLABS_API_KEY` is set, voiceover. Changes apply to the next render. See [Video walkthroughs](video-walkthroughs.md).
- **People** (admins only): everyone who has signed in, with a role of Admin or Member. Admins change roles here. Nobody changes their own role, and an admin set in the server config is locked. See [Claude accounts](claude-accounts.md#admins).
- **Channels**: Slack, Linear, Sentry, email, GitHub and Drone CI with their status, a Required badge on GitHub and a link to the setup docs. The email row shows the address to forward mail to. See [Channels](channels.md).
- **System**: host resources, queues, requests, traces, logs and errors for the Yak installation, with navigation between them. Yak admins also see which sandboxes are awake.
