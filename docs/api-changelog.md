# API changelog

One section per client API, one entry per protocol number: the date, what changed, and what clients must do.

A new endpoint, optional request field, response field, event type, error code, or an enum value in a field the contract marks as open is not breaking and needs no bump. Clients ignore what they do not know and treat an unknown enum value as "other". Removing or renaming a field, changing a type or meaning, making an optional request field required, changing an error code or status, removing an enum value, or changing a push payload key or realtime event shape is breaking and raises `protocol`.

The contract fixtures in `tests/Fixtures/cli-api/v1` and `tests/Fixtures/app-api/v1` pin the response shapes. Changing one is a deliberate edit that is checked against the rule above.

## CLI API

### Protocol 1 (2026-11-13)

The first protocol: device flow sign-in, `me`, repository resolution, teleport and session files.

## App API

### Protocol 1 (2026-11-14)

The first protocol: error contract, phone devices, pairing, `instance` and `me`.

`POST device/pair` takes an optional `request_id` (a UUID). A repeat with the same code and `request_id` inside the code's 120 seconds returns the first response, so a lost response can be retried. `POST device/token` also takes an optional `request_id` (a UUID). A repeat with the same `device_code` and `request_id` within 60 seconds of the first success returns the same response, and still honors the poll interval. A poll without a matching `request_id` never gets the token a second time.

#### Threads (2026-11-15)

Additions under spec 10.3, so the protocol stays 1.

New endpoints: `GET threads`, `GET threads/new`, `GET threads/new/repository-access`, `POST threads`, `GET threads/{thread}`, `POST threads/{thread}/seen`, `GET` and `PUT threads/{thread}/share`, `GET threads/{thread}/events`, `GET threads/{thread}/events/{sequence}`, `POST threads/{thread}/messages`, `PATCH` and `DELETE threads/{thread}/queued-messages/{message}`, `POST threads/{thread}/clarification-answers`, `POST threads/{thread}/stop`, `POST threads/{thread}/continue`, `POST threads/{thread}/restore/retry` and `POST broadcasting/auth`. Threads are addressed by public id; a numeric id answers 404. Queued messages are addressed by their `uuid`.

New event type: `question`, which carries the open question of a thread (`open: false` when it closes).

New fields: `starred` in the list; the `closed` filter and `search` on the list; `type` on every root row of `starred`, `needsYou` and `threads`; task items in `needsYou` (with `isPrivate`, true when the task's thread is private); `panes`, `question` and `latestSequence` on thread show; `sendKey`, `mode` and `canEditText` on message rows; `uuid` (null for `/clear`) and `queued` in the send response.

New 409 codes: `nothing_to_continue`, `nothing_to_restore`, `question_closed`. New 403 code: `not_your_message`, when someone other than the author edits the text of a queued message (mode switches and withdraw keep the drive-rights rule). Message rows carry `canEditText`, true only for the asking author of a still queued message.

`POST threads/{thread}/messages` and `POST threads` answer `503 unavailable` while the same `send_key` is still being handled; the client retries with the same key.

A `stream.reset` event carries `data` as an empty JSON object (`{}`), in broadcasts and in catch-up. A 403 on every client endpoint answers "You do not have access to this.".

New field on task show, so the protocol stays 1: `task.isFollowUp`, true when the conversation's latest task is a follow-up (a review follow-up then cannot re-run the review).

New field on task show, so the protocol stays 1: `progress.steps.*.failed`, true on the CI step while Yak retries after a CI failure. The CI step's `label` now follows the task: "Waiting for CI" while CI runs, "CI failed, retrying" during a retry, and "CI passed" otherwise.

New field on task show, so the protocol stays 1: `task.repositorySetup`, null unless the task failed or expired on a repository that has no sandbox snapshot. It then carries `repo`, `settingsUrl`, `isSettingUp`, `continuesAfterSetup` and `failedSetup` (`at` and `url` of the last failed setup, or null), so the client can offer to set the repository up.

New fields on thread show, so the protocol stays 1: `localTurnStints`, every stretch of turns run on a computer (`localTurns` keeps the first one), and `teleport.environmentSwitches`, every move between Yak and a computer (`teleport.environmentSwitch` keeps the latest one).

Queued messages go out when a turn completes, without the person sending anything else. A switch to Steer during a running turn hands the message to that turn at once.

The web header's needs-you count is the same number as `needsYouCount` in `me` and in the list: Mine threads plus tasks outside an open thread.

A thread that has not run a turn yet lists its starting request as a pending message in `messages` (`id` `initial-message`, `uuid` null), so a new thread shows its first message at once; this adds a row, not a field.

Contract fixtures: `threads`, `thread`, `thread-question` (a thread with an open question), `thread-events`, `thread-event`, `thread-message-sent`, `thread-message-queued`, `thread-message-clear`, `queued-message-updated`, `clarification-answers`, `thread-stop`, `thread-continue`, `thread-restore-retry`, `thread-new`, `thread-store`, `thread-repository-access`, `thread-share`, `thread-share-updated`, `broadcasting-auth-private`, `broadcasting-auth-presence`, `error-thread-blocked`, and the recorded event lists `events/simple-turn`, `events/steer`, `events/question`, `events/failed-turn`, `events/stream-reset` and `events/pointer`.

Effort level, so the protocol stays 1: every place that carries a `model` also carries an optional `effort` (`low`, `medium`, `high`, `xhigh` or `max`; null means the model's default). Request: `effort` on `POST threads`, `POST threads/{thread}/messages` and `POST threads/{thread}/stack/children`. Response: `effort` next to `model` in thread show (`composer`, `brief`, `environment`, message rows and stack children), `defaultEffort` on accounts and `effort` in `defaultsByRepo`. Each model in `models` lists the levels it supports in `efforts` (`latest.efforts` and `older[].efforts`, lowest first, empty when the model has no effort control).

New field on task show, so the protocol stays 1: `stop`, null unless the task failed or expired with a recorded reason, or is waiting for its repository's setup. It then carries `kind` (`yak_stopped`, `needs_you` or `yak_retrying`; treat an unknown value as `yak_stopped`), `summary`, `detail` (the error text, or null), `nextStep` and `isWaitingForSetup`.
