#### Request, progress and run details on the thread (2026-11-17)

Additions, so the protocol stays 1.

`GET threads/{thread}` has three new top-level keys. `request` is `{source, starter, responsibleName, status}`: `source` and `starter` are the same objects the thread rows carry, and `status` is `{key, label, tone}` with `key` one of `working`, `needs_you`, `waiting_for_co_owner`, `stopped`, `done`, `closed`. `progress` is `{steps: [{key, label, tooltip, state}]}` with `state` one of `done`, `current`, `failed`, `upcoming`, or null when the work is finished or the thread is closed. `runDetails` is `{runCount, stepCount, attempts, durationSeconds, costUsd, apiSpendUsd, sessionId, startedAt, completedAt, errorLog, errorLogTruncated}`; `errorLog` is only set when the latest run failed and is cut at 16 KB.
