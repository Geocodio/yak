#### Widgets (2026-11-17)

Additions under spec 10.3, so the protocol stays 1.

New endpoints: `PUT` and `DELETE push-tokens/widget`, on the same route as the other push token kinds. The widget token is kept beside the alert token for one device, and `PUT` answers 409 `push_environment_mismatch` for a build from the other environment, as for the alert token.

Reload push. When the needs-you count or the set of running threads changes, Yak sends the phone a WidgetKit push: `apns-push-type: widgets`, topic `<bundle id>.push-type.widgets`, priority 5, payload `{"aps":{"content-changed":true}}`. It carries no thread data. A phone gets at most one a minute, and the last change in a window still arrives.

Contract fixture: `push/widget-reload`.

`features.widgets` in `GET me` stays `true` and now means `PUT push-tokens/widget` exists; it was already `true` before this change, so clients probe the route and treat `404` as no widget push.

A reload follows a change within about 10 seconds, and a device gets at most one a minute; a change inside that minute arrives at its end.

Known limit: a change written by a bulk query (the repository setup block) can leave a widget stale until the next change or the system's own refresh; no thread data is exposed either way.
