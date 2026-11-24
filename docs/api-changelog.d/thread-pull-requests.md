#### Pull request state on thread show (2026-11-24)

New field on thread show, so the protocol stays 1: `pullRequests`, a list (empty when the thread has no pull request) with one entry per pull request of the thread's set, in the web's merge order. Each entry has `number`, `url`, `repository` (the GitHub name), `state` (`open`, `draft`, `merged` or `closed`) and `checks` (`passing`, `failing`, `pending` or null). Yak reads both from what it already stores, so the field never calls GitHub. Today `state` is never `draft`, because Yak does not record drafts, and `checks` is null when no checks were seen. Clients treat an unknown `state` as `open` and an unknown `checks` as null. `pullRequest` (the first pull request) and `thread.pullRequestCount` are unchanged. List rows do not carry the state, matching the web sidebar.

Contract fixture: `thread-pull-requests`; every thread show fixture gains `pullRequests`.
