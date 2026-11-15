#### Push (2026-11-16)

Additions under spec 10.3, so the protocol stays 1.

New endpoints: `PUT` and `DELETE push-tokens/{kind}` (`alert` and `live_activity_start`), `POST push-tokens/alert/test`, `GET` and `PUT notifications`, `PUT live-activities` and `POST threads/{thread}/live-activity-channel`. `PUT live-activities` drops unknown and hidden thread ids silently and answers 204 either way.

New 409 codes: `push_unavailable` (this Yak has no APNs key or the key cannot be used), `push_environment_mismatch` (with `details.expected` and `details.given`), `push_rejected` (with `details.reason`, Apple's reason word), and `no_push_token` (the test push for a phone with no alert token).

New field: `liveActivityChannel` on thread show, the id of the thread's APNs broadcast channel, or null until the phone asks for it. `me.push.environment` is never null now: it is `production` or `sandbox`, whichever this Yak sends to.

Push payloads. An alert carries `aps` (`alert`, `badge` set from `needsYouCount`, `sound`, `thread-id`, `filter-criteria`, and `interruption-level`, which is `time-sensitive` for `needs_you`) and `yak` (`event`, `kind`, `id`, `instance`, `path`, `needsYouCount`). A Live Activity start, update and end carry `aps.event` (`start`, `update`, `end`), `aps.timestamp` and `aps.content-state` (`state`, `step`, `turnStartedAt`, `isPrivate`); a start adds `attributes-type`, `attributes`, `input-push-channel`, `stale-date` and an alert. `yak.event`, `yak.kind` and `content-state.state` are open enums: a client treats a value it does not know as "other". A private thread shows "Private thread" and an empty `step` to every viewer.

Contract fixtures: `notifications`, `notifications-updated`, `push-test`, `thread-live-activity-channel`, `error-push-unavailable`, `error-push-environment-mismatch`, `error-push-rejected`, `error-no-push-token`, `push/alert-needs-you`, `push/alert-turn-finished`, `push/alert-pull-request`, `push/alert-private`, `push/alert-account`, `push/live-activity-start`, `push/live-activity-start-private`, `push/live-activity-update` and `push/live-activity-end`. The fixtures `thread` and `thread-question` changed by the added `liveActivityChannel` key only.
