#### Changes, tasks and observations (2026-11-16)

Additions under spec 10.3, so the protocol stays 1. The web keeps its numeric observation `taskId` and its lenient list parsing.

New endpoints: `GET threads/{thread}/diff`, `GET threads/{thread}/diff/files/{file}`, `POST threads/{thread}/pull-request`, `GET threads/{thread}/pull-requests`, `PUT threads/{thread}/pull-requests/order`, `POST threads/{thread}/pull-requests/{repository}/fix`, `GET tasks`, `GET tasks/{task}`, `POST tasks/{task}/retry`, `/cancel`, `/rerun-review`, `/retry-render` and `/reroute`, the co-owner and clarification routes below, and `GET observations`. Two signed, session-free routes serve images and files in task HTML: `app-media/task-attachments/{attachment}` and `app-media/tasks/{task}/pr-images/{asset}`. Signed links for research artifacts work the same way.

New fields: `canOpenPullRequest` on thread show, `diffTotals.filesCount`, and PR-bound totals in `diffTotals` (scratch-only files are not counted). A successful diff capture appends one `diff.updated` event whose `data` is an empty JSON object (`{}`), in broadcasts, in catch-up and in `GET events/{sequence}`.

Repeat rules: `POST threads/{thread}/pull-request` answers 202 `opening` while the job runs, 200 `open` once the pull request exists and 200 `held` when a change is held. It does not ask for the private-thread acknowledgement when the pull request already exists or is held. Task actions answer the status of the state already reached (`queued`, `cancelled`, and so on), so a second tap does nothing.

Locks: every lock wait is at most five seconds. A wait that runs out answers `503 unavailable` with a sentence, for task actions, co-owner decisions, tag and decline included.

New refusal codes: `nothing_to_publish`, `ci_not_failing`, `task_not_retryable`, `task_not_cancellable`, `not_a_review`, `review_already_queued`, `pull_request_unknown`, `github_unavailable`, `nothing_to_render`, `task_not_reroutable`, and the co-owner and early warning reasons below as codes. A share recipient gets 403 `forbidden` on every drive route.

On task detail `coOwner.viewer.access` is `error` when GitHub does not answer within 3 seconds.

Contract fixtures: `thread-diff`, `thread-diff-none`, `diff-file`, `diff-file-binary`, `diff-file-too-large`, `thread-with-changes`, `thread-with-pull-request`, `pull-request-opening`, `pull-request-open`, `pull-request-held`, `pull-requests`, `pull-requests-none`, `pull-requests-order`, `pull-request-fix`, `tasks`, `tasks-empty`, `task`, `task-question`, `task-read-only`, the `task-*` action and co-owner fixtures, `observations`, `observations-empty` and the `error-*` fixtures beside them.

Co-owner decisions and task answers: `POST tasks/{task}/co-owner/approve`, `/request-changes` (`feedback`, required, at most 5000 characters), `/reject` and `/withdraw` answer `{"status": "approved" | "changes_requested" | "rejected" | "withdrawn"}`. A repeat by the person who made the decision answers the same status and does nothing. `POST tasks/{task}/clarification-answers` takes `answers` and `note` as the thread route does and answers `{"status": "sent"}`.

Refusals use the reason as the code: 404 `not_found` (no co-owner request on the task), 409 `already_resolved`, 409 `not_linked` (with `details.connectUrl`, the web page that links GitHub), 403 `no_write_access` and `not_requester`, 503 `access_check_failed`, 503 `github_failed` (with `details.githubStatus` when GitHub answered), 409 `question_closed` for answers that came too late. A busy task answers 503 `unavailable` after five seconds.

Approve opens the pull request before it answers and can take up to 60 seconds. A client that times out reloads the task: a second tap answers `approved` or `already_resolved`.

Early warning: `POST tasks/{task}/co-owner/tag` (`user_id`, required) answers `{"status": "tagged"}` and `POST tasks/{task}/co-owner/decline` answers `{"status": "declined"}`; a repeat of the answer already given answers the same. `GET tasks/{task}/co-owner/candidates` answers `{"candidates": [{"id", "name"}], "isComplete"}`; the server checks GitHub for at most 8 seconds and answers `isComplete: false` with the people found so far. The list is empty for anyone but the requester.

The candidates list answers `isComplete: false` when a GitHub check failed, so an empty list is only final with `isComplete: true`.

Refusals: 404 `not_found` (the task has no early warning), 403 `not_requester`, 409 `not_linked`, `no_write_access` and `already_decided`, 503 `access_check_failed`, 503 `unavailable` after a five second lock wait.
