#### Slash commands, the step limit and stopping agents (2026-11-24)

Additions, so the protocol stays 1. An older app keeps working: it ignores the new keys and never calls the new routes.

New fields on thread show (`GET threads/{thread}`), the same data the web composer and Agents panel read:

- `slashCommands`: `{commands, capturedAt}`. Each command is `{name, description, argumentHint, kind}` with `kind` `yak` (handled by Yak, for example `clear`), `skill` or `command`; `argumentHint` and `capturedAt` can be null. The list is what the web menu shows when a person types `/`, and it is visible to everyone who can see the thread (a share recipient included). A command is sent as plain message text, `/review the diff`, through the existing `POST threads/{thread}/messages`; Yak queues it as the next turn when a turn is running.
- `agents`: `{runningCount, running}`. `running` lists up to 20 of the agents and workflows Claude runs in the thread, oldest first, each `{id, name, state, startedAt}` with `state` `running` or `stopping` (a stop was asked for and the agent has not finished). `runningCount` counts all of them. The agents tracked are the same rows the web panel shows; with the subagents flag off `agents` is empty. They change with the `agent.started`, `agent.progress` and `agent.finished` thread events the app already receives (the event data is the full agent row); reload thread show, or poll it, to refresh the short list.
- `composer.turn` was already served (`{steps, limit, canAddSteps, stepsToAdd}`, null while no turn runs): `steps` is the steps taken in the running turn, `limit` its step limit (null when a person started the turn, which has none), `canAddSteps` whether the limit can be raised and `stepsToAdd` how many steps one raise adds.

New routes, both for people who can drive the thread (`403` for a share recipient, `404` for a thread the person cannot see), throttled to 30 a minute per device, in a bucket no other route shares:

- `POST threads/{thread}/step-limit` raises the step limit of the running turn by `composer.turn.stepsToAdd`, as the web's "Add 300 steps" does, and answers `{"status": "raised", "added": 300, "turn": {...}}` with the new `composer.turn`. Each call adds again; it is not idempotent. With no running turn, or a turn that has no limit, it answers `409` with error `no_step_limit`.
- `POST threads/{thread}/agents/stop` asks the runner to stop every running agent of the thread and answers `{"status": "stopping" | "idle", "stopped": 2, "agents": {runningCount, running}}`. It is idempotent: with no running agent it answers `200` with status `idle` and `stopped` 0. The agents that remain are listed with `state` `stopping` until each finishes. With no runner left, the agents are marked failed at once, as on the web. With the subagents flag off it answers `404` (the app route's own rule; thread show then reports no agents). There is no per-agent stop.

Contract fixtures: `thread-composer`, `thread-step-limit`, `thread-agents-stop`, `thread-agents-stop-idle`, `error-no-step-limit`; every thread show fixture gains `slashCommands` and `agents`.
