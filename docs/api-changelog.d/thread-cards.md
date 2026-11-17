#### Cards above the composer on the thread (2026-11-17)

Additions, so the protocol stays 1.

`GET threads/{thread}` has six new top-level keys. `card` names the one card that needs a person now, one of `question`, `repository_question`, `early_warning`, `co_owner`, `stopped`, or null. `repositoryQuestion` is `{prompt, options, selected, canAnswer}` while the thread has no repository and Yak offers some, else null. `earlyWarning` and `coOwner` are the early warning notice and the co-owner request panel the task page shows, computed for the viewer, else null. `stopped` is `{kind, summary, detail, nextStep, isWaitingForSetup}` for a stopped or cancelled head task (`kind` is `needs_you`, `yak_retrying`, `yak_stopped` or `cancelled`), else null. `actions` is `{canStop, canRetry, canCancel, canMove, moveTargets, canRequestReview, canRetryRender}`; every flag is false for a viewer who cannot drive the thread and for a closed thread.
