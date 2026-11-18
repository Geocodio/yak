#### Queued messages in the thread conversation (2026-12-09)

Additions, so the protocol stays 1.

`messages` on `GET threads/{thread}` gains one row for each message that was queued while Yak worked and then started a turn, in the order it was queued and before the message that started the turn. Each row has the existing fields: `text`, `authorName`, `via` and `isReply` for a message from Slack, Linear or GitHub, `sentAt` and `attachments`. A turn that starts from queued messages alone no longer has a row with the task description in place of the queued text.
