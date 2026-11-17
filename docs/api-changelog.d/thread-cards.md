#### Cards above the composer on the thread (2026-11-17)

Additions, so the protocol stays 1.

`GET threads/{thread}` has six new top-level keys. `card` names the one card that needs a person now, one of `question`, `repository_question`, `early_warning`, `co_owner`, `stopped`, or null. `repositoryQuestion` is `{prompt, options, selected, canAnswer}` while the thread has no repository and Yak offers some, else null. `earlyWarning` and `coOwner` are the early warning notice and the co-owner request panel the task page shows, computed for the viewer, else null. `stopped` is `{kind, summary, detail, nextStep, isWaitingForSetup}` for a stopped or cancelled head task (`kind` is `needs_you`, `yak_retrying`, `yak_stopped` or `cancelled`), else null. `actions` is `{canStop, canRetry, canCancel, canMove, moveTargets, canRequestReview, canRetryRender}`; every flag is false for a viewer who cannot drive the thread and for a closed thread.

The `thread` event of the thread channel (and its catch-up copy) has a new `showKey` string. It changes whenever one of the cards above changes, so a client that reloads `GET threads/{thread}` on a changed `showKey` stays current. Clients that do not know the key can ignore it.

`walkthrough` has three new keys: `isRenderFailed`, `renderError` and `canRetryRender`. A repository setup `notice` (`waiting_for_repository`, `repository_setup_failed`) has `canContinueAfterSetup` and `failedSetupRunUrl`. `composer.state` can be `stopped`: the head task stopped without a pull request, and a message sent to the thread retries it with the message as a note. `POST threads/{thread}/messages` then answers with the same `status: "sent"`, plus a `notice` sentence in the result.
