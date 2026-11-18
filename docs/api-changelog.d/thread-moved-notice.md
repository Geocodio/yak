#### Moved notice on a closed thread (2026-11-18)

An addition, so the protocol stays 1.

`notice.kind` on `GET threads/{thread}` has a new value, `moved`, for a thread that closed because its request moved to another repository. `title` reads "Moved to <repository>" (or "Moved: waiting for a repository"), `body` says the request continues in a new thread, and `canContinue` is `false`. The notice also carries `repo`, `threadId` and `threadTitle` of the thread that continues it. All three are `null` when the viewer cannot see that thread. Clients that do not know the kind can show `title` and `body` as they are.
