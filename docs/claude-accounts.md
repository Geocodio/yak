# Claude Accounts

A Claude account is the credential Yak uses to talk to Claude. Every thread runs on exactly one account. There are two kinds: a setup token (from your Claude subscription) and an API key. Accounts you add belong to you. Yak accounts belong to the installation and are used for unattended work.

Yak opens pull requests for human review and serves branch previews. It never merges and never deploys, whichever account it runs on.

## Adding An Account

1. On your own machine, run `claude setup-token` and copy the token it prints. For an API key, copy the key from the Anthropic Console.
2. In Yak, open Settings > Claude accounts and choose Add account.
3. Paste the secret and give the account a name, for example "Personal" or "Geocodio work". Setup tokens carry no email or organization, so the name is how you and your teammates tell accounts apart.
4. Pick an icon and a color. They show next to the account in the thread sidebar and in pickers, so you can see at a glance which account a thread runs on.

Before saving, Yak checks the secret with one tiny Haiku turn. If the check fails, nothing is saved and the page tells you why. Once saved, the secret is never shown again, not even to you. A setup token is marked to expire one year after you add it. You can edit that date.

## Defaults

- Overall default: the account Yak uses for you when nothing more specific applies. Mark one account as your default on the Claude accounts page.
- Per repository: on a repository's page, "Your Claude account for this repo" lets you pick an account, and optionally a model, for that repo only.
- Model: each account has a default model. A repo default or your choice on a thread overrides it.

## How A Thread Gets Its Account

When a thread starts, Yak uses the first of these that applies:

1. The account you pick in the picker on the new-task form.
2. Your default for that repository.
3. Your overall default account.
4. The repository's unattended account, then the global Yak account. This is also what a thread uses when nobody started it (Sentry, Linear automation, flaky tests) or when the person who started it has no working account.

The model follows the same order.

The account is fixed for the life of the thread. Every turn runs on it, including turns sent by other people. This keeps Claude's prompt cache warm. To continue on a different account, start a new thread.

## Concurrency

Each account has a limit on how many turns run at once (1 to 64, default 16). A turn that arrives when the account is full waits and shows its place in the queue. It starts as soon as a slot frees up.

## Unattended Work

Work nobody asked for directly runs on a Yak account. Admins choose which one per repository, under Unattended account on the repository page. If a repository does not set one, the global Yak account is used. Everyone can see these accounts, only admins can change them.

## How Your Token Stays Safe

The agent in a sandbox has full control of that sandbox, so Yak never puts your real token there.

- Your token stays on the Yak host.
- Each running thread gets a random stand-in. It works only from that thread's own sandbox, only for requests to Claude's message API, and only while the thread is alive.
- A small proxy on the host swaps the stand-in for the real token on each request.

## When An Account Stops Working

If Anthropic rejects the token, Yak marks the account invalid, stops the turn and tells the account's owner in Slack. The message names the account and links to Settings > Claude accounts. Yak never falls back to a different account, because that would quietly bill someone else. If the account runs out of usage, the thread waits and continues when the limit resets.

Yak also warns the owner 14 days before a token expires.

## Rotating And Removing

- Rotate: open the account and paste a new secret. Yak checks it the same way. If the check fails, the old secret stays.
- Remove: delete the account from the Claude accounts page. Resting runners on it are ended. Threads keep their history and are blocked until their owner starts a new thread on another account.

Only the owner of an account can rotate or remove it. Admins manage the Yak accounts.
