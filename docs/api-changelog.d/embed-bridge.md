#### Embedded thread bridge (2026-11-20)

Additions under spec 10.3, so the protocol stays 1. The app's web view shows the thread page at `/app/embed/threads/{thread}` and talks to it through a script bridge. The server announces support through `me.web.embed`.

Page to app: `window.webkit.messageHandlers.yak.postMessage({ v: 1, type, ...payload })`. App to page: `window.__yakEmbed.receive({ v: 1, type, ...payload })`, called with `callAsyncJavaScript` arguments, never string interpolation. `v` rises only on a breaking change; a message with another `v`, an unknown `type` or a malformed payload is ignored on both sides. Native accepts a message only from the main frame whose origin host is the instance host, and treats every payload as untrusted.

Page to app:

- `ready` `{ threadId, url, latestSequence, title }`, once per thread when the live feed has caught up (or after 3 s).
- `threadChanged` `{ threadId, previousThreadId }`, when the page now shows another thread (a move, an `open`).
- `link` `{ url }`, for a visit to a URL outside `/app/embed/`; the page cancels the visit.
- `openPane`, flat: `{ pane: "files", mode: "changed" | "everything", path? }`, `{ pane: "preview", url? }`, `{ pane: "terminal" }`, `{ pane: "overview", section? }`.
- `quickLook` `{ url, name, mime? }`, for a file download; native fetches with the session cookie.
- `cardAnswered` `{ card, action, ok }`, with `card` one of `question`, `co_owner`, `repository_question`, `stopped`, `early_warning`.
- `unsentAction` `{ key, action: "retry" | "edit" | "discard" }`.
- `auth` `{ state: "expired" }`, on a 401 or 419; native runs the hand-off again.
- `error` `{ kind: "http" | "network" | "script", status? }`, at most one per 10 s.

App to page:

- `open` `{ threadId, fragment? }`, an Inertia visit to another embed thread.
- `focus` `{ fragment }`, matching `^[A-Za-z0-9_-]{1,64}$`, for example `turn-812` or `card-question`.
- `scrollToBottom` `{}`.
- `outbox` `{ threadId, items: [{ key, text, status, reason, createdAt, attachmentCount }] }`, a full replace. `status` is `queued`, `sending`, `slow`, `waiting` or `refused`. An item is hidden as soon as the thread's messages carry a row whose `sendKey` equals its `key`.
- `refresh` `{}`.
- `connectivity` `{ online }`.

Contract fixtures: one file per message in `embed-bridge/`, named `page-<type>` and `app-<type>` (plus extra `page-openPane-*` files for each pane). A bridge test validates each against the page's own validators.
