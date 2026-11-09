# Threads

A thread is one conversation with Claude about one piece of work. It has one sandbox, one Claude session and one [Claude account](claude-accounts.md). The sandbox sleeps between turns, so you can come back to a thread hours later and carry on where it stopped. Every task Yak runs lives in a thread, and so do its follow-ups, CI retries and replies to Claude's questions.

A thread closes when its pull request merges or closes, when research finishes and nobody follows up, or after it has been idle for 30 days. A closed thread never reopens; start a new one.

## Change And Research

When you start a thread you choose a mode:

- **Change** lets Claude edit code, commit and open a pull request.
- **Research** is read-only. Claude explores the code and answers, and never touches the working tree.

A Change follow-up on a Research thread picks up in the same sandbox.

## Sending Messages While Claude Works

You do not have to wait. Type in the composer and send at any time. A message you send while Claude is working shows greyed out until Claude reads it, then moves into the conversation at the point where Claude took it in. A message that arrives while Claude writes its final answer appears after that answer.

Use **Stop** to interrupt the current turn.

## Choosing A Model Per Message

The model picker sits next to the send button. The model you pick applies from that message on, with no restart. The account stays the same.

## When A Thread Is Blocked

A blocked thread shows a notice in the conversation that says what happened and what to do.

| Notice | What it means | What to do |
|---|---|---|
| **Token rejected** | The account's token is invalid or expired. | The account's owner pastes a new token in Settings → Claude accounts. New messages are refused until the owner pastes a new token, or you start a new thread. A missing or removed account behaves the same way. |
| **Usage limit reached** | The account's plan is used up. | Nothing, Yak continues when the limit resets. Or start a new thread on another account. |
| **Waiting for a free sandbox** | Yak runs a limited number of awake sandboxes and all are busy. | Nothing. The turn starts as soon as one frees up. |

Yak never moves a thread to another account on its own.

## The Runaway Guard And Continue

To protect your plan from a loop, each turn has two limits: a number of assistant messages (300 by default) and a wall-clock time (120 minutes by default). When either trips, Yak interrupts Claude and shows a notice with a **Continue** button. Continue starts a new turn with fresh limits. Unattended threads ask in the task's channel instead.

Yak sets no cost limit. It still records what each turn cost, for visibility.

## The Thread Page

The conversation is on the left. A sidebar on the right can be shown or hidden, and Yak remembers your choice.

**Environment** shows:

- the sandbox state (running, paused or stopped) and what waking it costs
- the branch, the pull request and the branch deployment
- the thread's account and model, read-only
- who started the thread

**Changes** shows every file changed in the thread, measured from where its branch left the default branch, including uncommitted work. Each file lists added and removed lines; select one to read its diff.

A paused sandbox cannot run commands, so Yak captures the diff at the end of every turn. While Claude is working, the sidebar shows the diff from the last finished turn with a note: "Claude is still working". Binary files are listed without content. A file with more than 256 KB of diff is listed with a note that it is too large to show, and once a thread's diff passes 2 MB in total, the remaining files are listed by name only.

## The Thread List

The left sidebar lists your threads, newest activity first, under Today, This week and Older. Status dots show running, paused and blocked.

- **Needs you** comes first: a question Claude asked, a blocked thread, or a finished turn you have not looked at yet.
- **Mine** (the default) shows threads you started or took part in, plus unattended threads for repositories you follow. **Everyone** shows all of them.

## Wake Times

Yak pauses a sandbox the moment a turn ends. Waking a paused sandbox takes about a second. After 30 idle minutes, or sooner if Yak needs the memory, the sandbox is stopped. Waking a stopped one takes about 7 seconds, plus the repository's `cold_start` command if it has one. Your files, branch and conversation are kept in both cases. When a thread closes, its sandbox is destroyed.
