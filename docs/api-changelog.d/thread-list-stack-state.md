#### Sub-thread state in the thread list (2026-11-25)

An addition, so the protocol stays 1. Each sub-thread row in `children` of a `GET /api/app/v1/threads` row has two new keys: `stackState` and `takenOver`. `stackState` is the same value `GET threads/{thread}/stack` gives that sub-thread as `state`: `closed`, `failed`, `done`, `queued`, `starting`, `working` or `waiting`. `takenOver` is the same boolean the stack answer calls `takenOver`. Root rows do not carry either key. The single-row answers that return a sub-thread's list row (star, close) carry both keys too.

A client treats a `stackState` it does not know as absent and shows the row's list `status`. On an older server both keys are absent, so a client reads them as optional and falls back to `status` the same way.
