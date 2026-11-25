#### Agents in the embedded thread page (2026-11-24)

Additions to the embedded thread page and its bridge, so the protocol and the bridge version stay 1. An older app keeps working: it ignores the new page message, and the cards it shows today only gain a control.

- The route `threads.agents.log` (`GET /threads/{thread}/agents/{agent}/log`, the log of one agent) is open to the embed session. It is read-only: it applies the thread's visibility (a hidden thread answers 404) and finds the agent inside the thread (an agent of another thread answers 404). Stopping an agent and passing it a message stay closed to the embed session; the app stops agents through `POST threads/{thread}/agents/stop`.
- An agent card in the embedded page now expands to the agent's steps and offers "Open full log", as on the web. It still shows no Stop button.
- Every agent card has the element id `agent-<task id>` (characters outside `[A-Za-z0-9_-]` become `_`, at most 64 characters), so the app's `focus` message can scroll to it: `focus {"fragment": "agent-a0f3c9"}`. A nested agent has no card of its own; the card is the one of the agent Claude started.

New page-to-app message, sent only by the thread page:

- `agents` `{ running, newest }`: `running` is how many agents and workflows run now, and `newest` is the card id of the one that started last (see above), or null when none runs. The page sends it when that pair changes, after it has been steady for 300 ms, so a burst of agent events is one message. The page sends it once when the thread page mounts and once after a thread switch, also when nothing runs (`{ "running": 0, "newest": null }`), so the app's bar never keeps a stale count after a reload; after that it sends only on a change. Example: `{ "v": 1, "type": "agents", "running": 2, "newest": "agent-a0f3c9" }`.

This message has no fixture file under `embed-bridge/` yet: its shape is pinned by `resources/js/embed/bridge.test.mjs`. An app that decodes bridge messages by type must add `agents` and its fixture together.

Requests an agent card makes in the embed, all on the embed session's allow-list: `GET /app/embed/threads/{thread}` (route `app.embed.threads.show`) as a partial reload of the `agents` prop when an agent starts, finishes or is asked to stop; `GET /threads/{thread}/agents/{agent}/log` (`threads.agents.log`) for the steps, further pages and the 3 s refresh while the agent runs, also from the full-log overlay (the agent log route ignores a `search` query); `GET /threads/{thread}/events` and `/events/{sequence}` (`threads.events.index`, `threads.events.show`) and the `broadcasting/auth` endpoint for the live `agent.*` events.
