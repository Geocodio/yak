#### Web session (2026-11-20)

Additions under spec 10.3, so the protocol stays 1.

New endpoint: `POST web-session` takes `path`, one of the embedded pages `/app/embed/threads/{id}`, `/app/embed/deployments/{id}` or `/app/embed/pr-reviews/{owner}/{repo}/{number}` (no query, no fragment, no encoding), and answers 201 `{redeemUrl, code, expiresAt}`. The code lives 60 seconds, works once, and is bound to the phone, its user, the host the request came to and the path. The app loads `redeemUrl` as a POST with the form field `code` in a web view whose user agent carries ` YakApp/<version>`. The server answers 303 to the bound path and sets a restricted session cookie that only reaches the embedded pages and their reads and card actions. A bad `path` answers 422 `validation_failed`; minting is limited to 10 per minute per phone.

The cookie never authenticates `/api/app/v1` or `/api/cli/v1`. When the phone is removed, the person is deleted or 12 hours pass, the web view gets a 401; the app then mints a new code.

New field: `me.web` is `{"embed": 1}`. A client that does not find it must not offer the embedded pages.

Contract fixtures: `web-session` and `error-web-session-path`. The fixture `me` changed by the added `web` key only.
