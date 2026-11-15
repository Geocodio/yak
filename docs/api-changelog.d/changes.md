#### Changes, tasks and observations (2026-11-16)

Co-owner decisions and task answers: `POST tasks/{task}/co-owner/approve`, `/request-changes` (`feedback`, required, at most 5000 characters), `/reject` and `/withdraw` answer `{"status": "approved" | "changes_requested" | "rejected" | "withdrawn"}`. A repeat by the person who made the decision answers the same status and does nothing. `POST tasks/{task}/clarification-answers` takes `answers` and `note` as the thread route does and answers `{"status": "sent"}`.

Refusals use the reason as the code: 404 `not_found` (no co-owner request on the task), 409 `already_resolved`, 409 `not_linked` (with `details.connectUrl`, the web page that links GitHub), 403 `no_write_access` and `not_requester`, 503 `access_check_failed`, 503 `github_failed` (with `details.githubStatus` when GitHub answered), 409 `question_closed` for answers that came too late. A busy task answers 503 `unavailable` after five seconds.

Approve opens the pull request before it answers and can take up to 60 seconds. A client that times out reloads the task: a second tap answers `approved` or `already_resolved`.

Early warning: `POST tasks/{task}/co-owner/tag` (`user_id`, required) answers `{"status": "tagged"}` and `POST tasks/{task}/co-owner/decline` answers `{"status": "declined"}`; a repeat of the answer already given answers the same. `GET tasks/{task}/co-owner/candidates` answers `{"candidates": [{"id", "name"}], "isComplete"}`; the server checks GitHub for at most 8 seconds and answers `isComplete: false` with the people found so far. The list is empty for anyone but the requester.

The candidates list answers `isComplete: false` when a GitHub check failed, so an empty list is only final with `isComplete: true`.

Refusals: 404 `not_found` (the task has no early warning), 403 `not_requester`, 409 `not_linked`, `no_write_access` and `already_decided`, 503 `access_check_failed`, 503 `unavailable` after a five second lock wait.
