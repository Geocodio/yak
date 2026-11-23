#### Sub-thread state `starting` (2026-11-23)

An addition, so the protocol stays 1. The `state` of a sub-thread in `GET threads/{thread}/stack` has a new value, `starting`: the sub-thread is open, has not run a turn yet and is not waiting for a free spot. Before, such a sub-thread reported `queued`. `queued` now means only that the sub-thread waits for a free spot (`queued_for_slot_at` is set), and a research sub-thread never reports it. Clients show `starting` as Starting and, as for any new enum value, treat one they do not know as "other".

The `messages` array stays in the response; the web sidebar no longer shows it.
