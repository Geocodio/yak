#### Threads from before threads (2026-11-17)

Additions, so the protocol stays 1.

New fields on `GET threads/{thread}`: `isLegacy` and `legacyAnswers` (list of `runTaskId`, `html`, `at`). A thread made from an old task is closed and has no sandbox; its `messages` and `legacyAnswers` carry the stored history.
