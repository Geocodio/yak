#### Authors and repliers on the thread (2026-11-17)

Additions, so the protocol stays 1.

Each item of `messages` on `GET threads/{thread}` has four new fields. `via` is `{key, label, reference, url}` for the channel the message came from, or null for a dashboard message. `isReply` is true for a follow-up that came from a channel. `isRetry` and `retryNumber` mark a retry; a retry has an empty `text` and `bodyHtml`. `authorName` is never null: it falls back to "Yak", and `authorInitials` is never `?`.

`thread.canShare` is true when the person may open the share dialog: the owner, or any person who can drive a team thread nobody owns. Sharing such a thread makes that person its owner.
