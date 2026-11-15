#### Overview and sub-threads (2026-11-16)

Additions under spec 10.3, so the protocol stays 1.

New endpoints:

- `GET threads/{thread}/stack`, from a root or from any of its sub-threads.
- `POST threads/{thread}/stack/children` adds a sub-thread. `send_key` (a uuid) is required. A repeat with the same key answers the first `201 {id, name}` and creates nothing. A key already used by another person answers `422` on `send_key`.
- `POST threads/{thread}/stack/stop` answers `{"status": "stopping"}` at once; the turns stop in a job. `canStop` stays true on a stopped stack while a turn still runs, and a repeated stop then asks again.
- `PATCH threads/{thread}/stack/limit` with `limit` from 1 to `limit.max` answers `{"status": "updated", "limit": {...}}`. Queued sub-threads start shortly after the answer.
- `POST threads/{child}/stack/take-over`, `/split` and `/drop` act on a sub-thread that failed. They answer `taken_over`, `split_requested` and `dropped`, and a repeat answers the same. The note to the parent reaches it shortly after the answer, from the person and not from an agent.
- `GET threads/{thread}/environment`.
- `POST threads/{thread}/repositories/{repository}/retry` answers `{"status": "retrying"}`; retrying a setup that already runs answers the same.

New error codes (all `409`): `sub_thread_cannot_spawn`, `stack_stopped`, `stack_full`, `child_not_failed` and `nothing_to_retry`. `stack_full` has `details {count, max}` and the sentence "This thread has started {count} of {max} sub-threads, the most one thread can have.". `child_not_failed` has `details {state}`, and `takenOver: true` when the person drives the sub-thread now.

Every action answers `503 unavailable` ("Yak is still handling another request for this thread. Try again in a moment.") when the thread is busy. A hidden thread answers every route with the `404` of an unknown id, and a share recipient gets `403 forbidden` on every action and can read the stack and the environment.

Changed fields:

- The stack gains `stoppedAt` and `addChild {allowed, error, message}`. `addChild` carries exactly the `error` and `message` that `POST stack/children` would answer.
- `limit.awake` counts the open implement, mockup and general sub-threads that hold a sandbox, the same count the limit uses.
- Every thread list row gains `step`, the thread's current step or null. A client treats `step` like the title: never in a widget or on a lock screen for a row with `isPrivate: true`.

Contract fixtures: `stack`, `stack-child`, `stack-empty`, `stack-stopped`, `stack-child-created`, `stack-stop`, `stack-limit-updated`, `stack-take-over`, `stack-split`, `stack-drop`, `error-stack-full`, `thread-environment` and `thread-repository-retry`, plus `threads` regenerated with `step`.
