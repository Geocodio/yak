#### Milestones on the thread (2026-11-17)

Additions, so the protocol stays 1.

`GET threads/{thread}` has a new top-level key `milestones`: a list of `{key, label, tone, url, runId, afterSequence}`. `key` is one of `pushed`, `ci_passed`, `ci_failed`, `pull_request_opened`, `pull_request_held`, `walkthrough_ready`, `research_report`, and `tone` is one of `ok`, `warn`, `bad`. `runId` is the id of the run that reached it and `afterSequence` is the sequence of that run's last thread event, so a client shows the row after that run's answer. A run with no events yet has no milestones. A thread made from an old task keeps its milestones with `runId` set to the task id and `afterSequence` 0.
