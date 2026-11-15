#### Preview (2026-11-16)

Additions under spec 10.3, so the protocol stays 1.

New endpoints, all under `threads/{thread}/preview`, all addressed by public id (a numeric id answers 404):

- `GET status` answers 200 with the state of the thread's preview, plus `port`, `coldStartCommand` and `setup`. It runs no sandbox command and starts nothing. `state` is an open enum: `ready`, `waking_sandbox`, `starting_dev_server`, `failing`, `no_manifest`, `invalid_manifest`, `destroyed`, `closed`, `local_machine` and `waiting_for_slot`. A client treats an unknown value as "other".
- `POST warm` answers 204 at once. The wake runs after the response, so a failed wake never fails the call. A repeat within a few seconds wakes nothing more.
- `POST heartbeat` answers 204 and keeps the preview awake. A repeat is a no-op.
- `POST ticket` takes `path` (a string of at most 2048 characters that starts with a single `/` and stays on the preview host) and answers 200 `{url, expiresAt}`. The ticket is single use and lasts 60 seconds. A repeat mints a new ticket and stores nothing.
- `POST restore` answers 202 `{"status": "restoring"}` and needs drive rights.

Refusals: 409 `thread_closed`, 409 `thread_owned_by_device`, 409 `nothing_to_restore` ("The sandbox is still there, so there is nothing to restore."), 422 `validation_failed` on `path`, and 503 `unavailable` when previews are switched off. A person who may not see the thread gets the same 404 as for a thread that does not exist. A share recipient may use status, warm, heartbeat and ticket, and gets 403 `forbidden` on restore.

A restore repeated while one runs, or within 300 seconds of its dispatch, answers the same 202 and starts nothing. A failed restore (`blockedReason` `restore_failed`) can be restored again through this route whatever the preview state, since a step that fails after the sandbox was created leaves the state at the dev server's. A repeat after a finished restore answers `nothing_to_restore`; a client treats that as "already restored" and refetches status.

On the web, a direct POST to the restore route on a sandbox that is still there now answers 422 with the same sentence. The web button only shows for a destroyed sandbox, so nothing visible changes.

Contract fixtures: `thread-preview-status`, `thread-preview-status-starting`, `thread-preview-status-failing`, `thread-preview-ticket` and `thread-preview-restore`.
