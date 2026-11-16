#### Message attachments (2026-11-17)

Additions under spec 10.3, so the protocol stays 1.

Every message attachment's `url` and `downloadUrl` on `GET threads/{thread}`, and the links in `bodyHtml`, are 30-minute signed `app-media/task-attachments/{attachment}` links. They need no session and no token. Only the thread's own files are signed.

New fixture: `thread-attachments`.
