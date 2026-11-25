#### Channel cards on thread messages (2026-11-25)

Additions, so the protocol stays 1.

Each item of `messages` on `GET threads/{thread}` has a new `channelCard` field: null, or where a message from Slack, Linear or email came from. It is `{channel, url, reference, title, avatarUrl, mentions}`. `channel` is `slack`, `linear` or `email`. `url` links the Slack message or the Linear issue, and is null for email. `reference` is the Slack channel as `#name`, the Linear issue identifier, or the sender's email address. `title` is the Linear issue's title or the email's subject. `mentions` lists the names a Slack message mentions; `bodyHtml` wraps each in `<mark data-mention>`. A reply's `via.url` links the message it came from. Messages from before this field existed have `channelCard: null`.

Slack messages now reach the thread and the agent as Markdown: `<@U123>` becomes `@Name`, `<url|label>` becomes a Markdown link, and Slack's escaped characters are plain again.

Regenerated fixtures: every thread show fixture gains `channelCard: null`.
