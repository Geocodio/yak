#### Terminal and files (2026-11-16)

Additions under spec 10.3, so the protocol stays 1.

New endpoints: `POST threads/{thread}/terminal-sessions` (opens one shell; the request carries an `Origin` header with the instance URL), `GET threads/{thread}/files` (one folder), `GET threads/{thread}/files/content` (one file), `PUT threads/{thread}/files/content` (save) and `GET threads/{thread}/files/search` (find a file by name).

New error codes: `origin_not_allowed` (403, the `Origin` header is missing or not allowed), `sandbox_destroyed` (409), `no_sandbox` (409), `sandbox_unavailable` (409) and `file_conflict` (409). `sandbox_unavailable` carries `details.state`, an open enum (`waking`, `asleep`, `destroyed`, `closed_destroyed`, `on_device`), and the flags `canRestore`, `waitingForSlot` and `restoring`, which are always present. `file_conflict` carries `details.state` and `details.file` (null when the file was deleted meanwhile). The terminal also answers `thread_blocked` with `details.reason` `restore_failed` when a restore failed. A sandbox command that fails or times out answers `503 unavailable`.

New fields: `restoring` (always present) on the terminal's `202` `waiting` body; `truncated` on the folder listing, true when the folder was cut at the listing limit.

Limits: the files endpoints allow 1200 requests per minute per device. A save with a file body over the edit limit is a `422 validation_failed` on `content`; a save into a closed thread is `409 thread_closed`.

`POST threads/{thread}/restore/retry` now also restores a removed sandbox on an open thread, and answers `restoring` for a repeat while a restore is queued or running. A save whose response was lost can be sent again: when the file on disk already holds the saved content, the answer is `file_conflict` with `details.file.sha256` equal to the hash of the content sent, and the client treats that as saved. A repeat never writes twice.

Contract fixtures: `thread-terminal-session`, `thread-terminal-session-waiting`, `error-sandbox-destroyed`, `thread-files`, `thread-file`, `thread-file-binary`, `thread-file-saved`, `error-file-conflict`, `error-sandbox-unavailable`, `thread-files-search`.
