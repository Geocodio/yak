#### Expired co-owner request on a thread (2026-11-18)

No new fields. `GET threads/{thread}` returns a `stopped` object, and `card: "stopped"`, for a thread whose co-owner request expired: `kind` is `needs_you`, `summary` reads "Nobody decided within 7 days, so Yak did not open the pull request.", `nextStep` reads "Send a message to continue this work.", `detail` is null. Before this change `stopped` was null for that state. `actions.canRetry` stays false; a message to the thread starts the work again.
