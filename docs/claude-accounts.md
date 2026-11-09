# Claude Accounts

A Claude account is the credential Yak uses to talk to Claude. Every thread runs on exactly one account. There are two kinds: a **setup-token** (from your Claude subscription) and an **API key**. Accounts you add belong to you. **Yak system accounts** belong to the installation and are used for unattended work.

## Adding An Account

1. On your own machine, run `claude setup-token` and copy the token it prints. For an API key, copy the key from the Anthropic console.
2. In Yak, open **Settings → Claude accounts** and choose **Add account**.
3. Paste the secret and give the account a name, for example "Personal" or "Geocodio work". Setup-tokens carry no email or organization, so the name is how you and your teammates tell accounts apart.
4. Pick an icon and a colour. They show next to the account in the thread sidebar and in pickers, so you can see at a glance which account a thread runs on.

Before saving, Yak checks the secret with one tiny Haiku turn. If the check fails, nothing is saved and the page tells you why. Once saved, the secret is never shown again, not even to you. A setup-token is marked to expire one year after you add it; you can edit that date.

## Defaults

- **Overall default:** the account Yak uses for you when nothing more specific applies. Mark one account as your default on the Claude accounts page.
- **Per repository:** on a repository's page, **Your Claude account for this repo** lets you pick an account, and optionally a model, for that repo only.
- **Model:** each account has a default model. A repo default or your choice on a thread overrides it.

## How A Thread Gets Its Account

When a thread starts, Yak uses the first of these that applies:

1. The account you pick in the picker on the new-task form or when you open a thread from the dashboard.
2. Your default for that repository.
3. Your overall default account.
4. The repository's unattended account, then the global Yak system account. This is also what a thread uses when nobody started it (Sentry, Linear automation, flaky tests) or when the person who started it has no active account.

The model follows the same order.

**The account is fixed for the life of the thread.** Every turn runs on it, including turns sent by other people. Nobody can switch it. This keeps Claude's prompt cache warm: a different account would restart the session and bill the whole history again as cold input. To continue on a different account, start a new thread.

An unattended thread stays on its Yak account even after a person replies to it.

## Unattended Work

Work nobody asked for directly runs on a Yak system account. Admins choose which one per repository, under **Unattended account** on the repository page. If a repository does not set one, the global Yak system account is used. Non-admins can see these accounts but not change them.

## How Your Token Stays Safe

The agent in a sandbox has full control of that sandbox, so Yak never puts your real token there. Instead:

- Your token stays on the Yak host.
- Each running thread gets a random stand-in. It works only from that thread's own sandbox, only for requests to Claude's message API, and only while the thread is alive.
- A small proxy on the host swaps the stand-in for the real token on each request and forwards it to Anthropic.

The agent can use its own stand-in while its thread is alive, but it can never read or carry away your real token. Another sandbox's stand-in, or an unknown one, is refused.

## When An Account Stops Working

If Anthropic rejects the token, Yak marks the account **invalid**, stops the turn, and blocks the thread. Yak notifies the account's owner and asks them to paste a new token. Yak never falls back to a different account, because that would quietly bill someone else.

If the account runs out of usage, the thread is blocked with a different message. Yak continues on its own when the limit resets. See [Threads](threads.md#when-a-thread-is-blocked).

## Rotating And Removing

- **Rotate:** open the account and paste a new secret. Yak validates it the same way. Threads already running on the account pick the new secret up on their next request.
- **Remove:** delete the account from the Claude accounts page. Threads that run on it are blocked until their owner starts a new thread on another account.

Only the owner of an account can rotate or remove it.
