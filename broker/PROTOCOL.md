# Broker, runner and Yak tool protocols

One document for both sides of the wire: the Go broker (`broker/`) and the PHP thread driver (`App\Services\Threads\Contracts\BrokerStreams`, with `RedisBrokerStreams` and `FakeBrokerStreams`). The Node runner speaks the runner protocol (section 3). Change this file in the same commit as any wire change.

## 1. Redis streams

A channel is one runner's pipe. Its name is `threads:{threadId}` for a thread, or `runs:{runId}` for a one-shot run (PR review, setup) that has no thread.

| Key | Type | Written by | Content |
|---|---|---|---|
| `broker:channels` | set | PHP | Names of the channels the broker serves. PHP adds a channel before its first command and removes it when the runner ends for good. |
| `broker:containers` | hash | broker | Channel name to the container its runner last ran in, so a restarted broker can kill leftovers. |
| `broker:alive` | key | broker | Heartbeat the app reads to tell whether the broker is up. |
| `{channel}:in` | stream | PHP | Commands to the broker. Consumer group `broker`. |
| `{channel}:out` | stream | broker | Runner lines and broker lines. Consumer group `drivers`. |
| `{channel}:dead` | stream | PHP | Entries of `:out` that kept failing, with `entry_id`, `reason`, `failed_at` and the original fields. |
| `broker:attempts` | hash | PHP | Failed handling count per `channel|entryId`, so the retry cap survives a driver restart. |

Both sides use a Redis connection without a key prefix. Nothing secret is ever written to Redis: `start_runner` carries only the thread's placeholder, and real credentials go from the app to the auth proxy over a local Unix socket.

### Inbound entries (`{channel}:in`)

One field, `command`, holding a JSON object with a `type`:

| `type` | Fields | Effect |
|---|---|---|
| `start_runner` | `container`, `uid`, `cwd`, `start` (the runner `start` line, section 3), optional `credentials_path` | Checks the mounted credentials file, then kills this channel's previous runner, starts a new runner under `setsid` (its command line carries a `--channel <name>` marker the per-channel kill matches), writes `start` to its stdin, and increments the channel's generation. `credentials_path` is the host path of the mounted `.credentials.json`; when empty the broker uses `YAK_BROKER_CREDENTIALS_FILE`; when both are empty the check is skipped. |
| `turn` | `uuid`, `text`, optional `priority` | Writes a runner `user` line. |
| `steer` | `uuid`, `text`, optional `priority` (`next` or `now`) | Same wire effect as `turn`; the driver tracks them differently. |
| `interrupt` | none | Writes an `interrupt` line. |
| `set_model` | `model` | Writes a `set_model` line. |
| `end` | none | Writes an `end` line: finish the current work and exit. |
| `kill` | none | Kills this channel's runner process tree with the full kill script. Other runners in the same container are untouched. |
| `tool_result` | `call_id`, `is_error`, `content` | Writes the answer to a `yak.tool_call` (section 4) to the runner's stdin. |

The broker deletes every inbound entry after processing it, whether or not it parses. A `turn` or `steer` with no live runner produces a broker line `{"type":"broker.no_runner","uuid":"..."}` (`call_id` for a `tool_result`).

### Outbound entries (`{channel}:out`)

Fields:

- `generation`: integer, starts at 1 and increments on every `start_runner`. A driver ignores lines of an older generation than the channel's current one.
- `sequence`: integer, monotonic per channel across generations. Handling is idempotent per sequence.
- `line`: one raw JSON line exactly as the runner wrote it, or a broker line.
- `origin`: `broker` for lines the broker wrote itself, empty for runner lines. Runner output cannot set this field.

Broker lines are objects whose `type` starts with `broker.`. Trust the `origin` field, never the line body: a runner can print a line that looks like a broker line, but it never carries `origin`.

| `type` | Fields | When |
|---|---|---|
| `broker.restarted` | none | The broker started and found this channel. |
| `broker.runner_started` | `generation` | After the `start` line was written to the new runner. |
| `broker.runner_exited` | `generation`, `exit_code` | The runner ended; `exit_code` is `-1` when it could not be started. |
| `broker.no_runner` | `uuid` or `call_id` | A `turn`, `steer` or `tool_result` found no live runner. |
| `broker.start_refused` | `reason`, `generation` | The broker refused to start the runner. No process was started and the generation still increments. `reason` is `credential_exposure` (the credentials file holds a Claude login that is not an allowed shared account, or cannot be read or parsed: fail closed; a missing file or one without `claudeAiOauth` is allowed) or `restart_cap` (more than 5 starts of this channel within 5 minutes). |
| `broker.kill_done` | `generation` | A `kill` command's script returned. |
| `broker.line_dropped` | `bytes` | A runner stdout line longer than 8 MiB was discarded without being forwarded; `bytes` is its size. Consumers log a warning and continue. |

A restarted broker reads the last `:out` entry of each channel to continue its generation and sequence, so the driver never trims that entry. The driver acknowledges an entry only after handling it, retries a failing entry a few times, then moves it to `:dead`. Entries left pending by a crashed consumer are claimed again after 60 s. After acknowledging, the driver trims `:out` to the last `yak.threads.stream_catchup_minutes` and deletes all three keys when the runner ends for good.

## 2. PHP interface

`BrokerStreams` methods: `register`, `unregister`, `channels(prefix)`, `command(channel, array)` (returns the entry id), `read(channels, consumer, blockMilliseconds, count)` (pending entries of this consumer first, new entries only when none are pending), `ack(StreamEntry)`, `trimBefore(channel, entryId)` (drops older `:out` entries, keeps the given one), `delete(channel)`, `recordFailure(StreamEntry)`, `deadLetter(StreamEntry, reason)`. An `:out` entry that is not a JSON object with numeric generation and sequence is dead-lettered by `read` and never returned.

## 3. Runner protocol

JSON lines on the runner's stdin and stdout. The runner lives in `/opt/yak-runner/runner.mjs` and drives `@anthropic-ai/claude-agent-sdk`.

In (broker to runner):

- `start`: `model`, `appendSystemPrompt`, `permissionMode` (`bypassPermissions`), `cwd`, `additionalDirectories`, `mcpServers`, `settingSources`, `resume` (session id or null), `placeholder` (the thread's auth proxy placeholder), `disallowedTools` (`ScheduleWakeup`, `CronCreate`, `CronDelete`, `CronList`). The runner builds the child environment explicitly and never inherits its own: `ANTHROPIC_BASE_URL` is the auth proxy, `CLAUDE_CODE_OAUTH_TOKEN` is the placeholder, `CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC=1`, `ANTHROPIC_API_KEY` and `ANTHROPIC_AUTH_TOKEN` are unset, `HOME=/home/yak`, `CLAUDE_CONFIG_DIR` comes from the thread, `CLAUDE_STREAM_IDLE_TIMEOUT_MS=1800000` and `CLAUDE_BYTE_STREAM_IDLE_TIMEOUT_MS=1800000`.
- `user`: `uuid`, `text`, optional `priority` (`next` or `now`). PHP assigns and stores every UUID.
- `interrupt`
- `set_model`: `model`
- `end`: finish the current work and exit.
- `tool_result`: see section 4.

Out (runner to broker): every SDK message as one JSON line, unchanged, plus runner lines `runner.started`, `runner.interrupted` (as soon as the runner has passed an `interrupt` to the SDK), `runner.error`, `runner.exit_after_result` and `runner.done`, plus `yak.tool_call` (section 4).

Lifetime: one runner per thread, alive between turns and across pauses. It is restarted with `resume` after a sandbox stop, a broker restart or a crash. A model change between turns uses `set_model` and needs no restart. The broker kills a runner with the full kill script (every descendant, then the session); a bare process-group kill orphans the Bash tool's processes.

## 4. Yak tool protocol

The runner exposes in-process MCP tools to Claude: `spawn_thread`, `send_message`, `list_threads`, `add_repository` and `request_refresh`. They need Yak's data, so the runner forwards each call to PHP:

1. The runner writes `{"type":"yak.tool_call","call_id":"<uuid>","tool":"<name>","input":{...}}` on stdout. It reaches PHP as an ordinary `:out` entry.
2. PHP answers with an inbound command `{"type":"tool_result","call_id":"<uuid>","is_error":false,"content":"<text>"}`. The broker writes it to the runner's stdin as is.
3. A call PHP never answers times out inside the runner after 120 s with an error result for Claude.

`call_id` is a fresh UUID per call. PHP handles a `yak.tool_call` once per `call_id` (the entry may be redelivered), and answers a call from a stale generation with nothing.

## 5. Credential socket

`yak:credential-socket` serves the real credential behind an auth proxy placeholder to the broker's auth proxy. The Unix socket path is `yak.threads.credential_socket` (default `/run/yak/credentials.sock`), file mode 0660. Every connection is also checked against `yak.threads.credential_socket_uid` (the broker system user) with the kernel's peer credentials (SCM_CREDENTIALS), so a peer with another uid gets `{"error":"forbidden"}` and nothing else.

One JSON line request, one JSON line reply:

- Request: `{"placeholder": "yakph-...", "container_ip": "172.20.0.5"}`. The request is capped at 4096 bytes.
- Reply on success: `{"kind": "setup_token"|"api_key", "secret": "...", "account_id": 12}`.
- Reply otherwise: `{"error": "unknown"}` for an unknown, revoked or expired placeholder, a container ip that is not the one the placeholder was issued for, and malformed JSON. All cases look the same on purpose. `{"error": "forbidden"}` is for a peer with the wrong uid.

The secret is never written to disk or logged. The proxy may keep a reply in memory for a short time; the proxy track owns that client and its cache.

## 6. Broker environment

| Variable | Meaning |
|---|---|
| `YAK_BROKER_REDIS_ADDR` | Redis address, default `127.0.0.1:6379`. |
| `YAK_BROKER_REDIS_PASSWORD` | Redis password. |
| `YAK_BROKER_CREDENTIALS_FILE` | Credentials file to check when a `start_runner` has no `credentials_path`. |
| `YAK_BROKER_SHARED_CLAUDE_REFRESH_SHA256` | Comma list of sha256 hex digests of the refresh tokens of the shared Yak Claude account(s) that may appear as `claudeAiOauth`. |
| `YAK_BROKER_CREDENTIAL_SOCKET` | Unix socket the auth proxy listens on, default `/var/lib/yak/credentials/redeem.sock`. |
| `YAK_BROKER_PROXY_ADDR` | TCP address the auth proxy listens on. Required: unset is a startup error. |
| `YAK_BROKER_PROXY_HOST_CIDRS` | Comma list of CIDRs allowed to connect besides the sandbox bridge subnet. A bad entry is a startup error. |
| `YAK_BROKER_PROXY_UPSTREAM` | Upstream base URL, default `https://api.anthropic.com`. |

## 7. Auth proxy

The broker runs an auth proxy for the sandboxes. A sandbox's Claude process talks to the proxy with a placeholder token and never holds a real credential. The proxy asks the Yak app for the real one over a Unix socket and swaps it in.

Credential socket: path from `yak.threads.credential_socket` (default `/run/yak/credentials.sock`), owner-only. The PHP side checks the peer credentials of every connection. One request line, one reply line, then the connection closes.

Request: `{"placeholder": "...", "container_ip": "..."}`

Reply: `{"kind": "setup_token"|"api_key", "secret": "...", "account_id": N}`, or `{"error": "unknown"}` when the placeholder is unknown, revoked or not bound to that address.

The IP binding is enforced by the app, never by the proxy. The proxy sends the TCP peer address of the incoming connection as `container_ip` and ignores `X-Forwarded-For`, `Forwarded` and every other client-supplied address header. Placeholders used for host-side validation are bound by the app to the address the host connects from (loopback or the bridge address).

Allowlist: `POST /v1/messages`, `POST /v1/messages/count_tokens`, and `HEAD /api/hello` (the CLI startup probe, forwarded without a credential). Every other method and path gets 404.

Header injection: the inbound `x-api-key` and `Authorization` headers are removed. A `setup_token` credential sets `Authorization: Bearer <secret>`; an `api_key` credential sets `x-api-key: <secret>`. Requests and responses stream without buffering, and status, headers and body are relayed verbatim.

Cache: credentials are cached in memory only, per placeholder and sandbox address, for 30 seconds. `AuthProxy.Revoke(placeholder)` drops a placeholder's entries and the broker calls it when a runner exits. An upstream 401 also drops the cached entry so a rotated secret is re-fetched on the next request.

Status mapping: 401 unknown or missing placeholder, 404 path not allowed, 502 upstream failure, 503 credential service down or replying garbage. Error bodies are generic and never contain the secret, the placeholder or the socket path.

Logging: every non-200 upstream response is logged with its status, safe headers and body shape (JSON key names, `error.type`, length). Body values, the secret and the placeholder are never logged.

Wiring: `NewAuthProxy(SocketCredentialSource{Path: socketPath}, upstreamURL, logger)`, then `Serve(ctx, listener)` on `YAK_BROKER_PROXY_ADDR` (`startProxy`, called from `runProxy` in `main`). The listener is wrapped in a peer filter that closes any connection whose peer is outside `YAK_BROKER_PROXY_HOST_CIDRS` and outside the subnet of the interface owning the listen IP, before HTTP runs. The validator's placeholder is bound by the app to the configured validation CIDRs. `Serve` shuts down gracefully on `ctx.Done` (10 s drain), uses a 10 s `ReadHeaderTimeout` and has no write timeout.

## 8. Wake proxy and SSH lookup

Two small host programs let `ssh` reach a sandbox without Yak knowing the container address. Both call the Yak app over HTTPS with `Authorization: Bearer <token>`; an unset token on the Yak side answers 401 to everything.

Environment (both programs): `YAK_SSH_URL` (Yak base URL), `YAK_SSH_TOKEN_FILE` (file holding the bearer token, mode 0600, never logged). The wake proxy also reads `YAK_WAKE_TIMEOUT` (Go duration, default `90s`): how long a connection may wait for a free sandbox.

Internal endpoints (all JSON):

- `GET /internal/ssh/listeners` returns `{"listeners": [{"address": "127.18.4.17", "port": 22}]}`, one per sandbox that may be reached. The wake proxy polls it every 5 s and binds one listener per address. Addresses outside `127.0.0.0/8` are ignored.
- `POST /internal/ssh/wake {"address"}` returns `{"state", "container", "message"}`. `state` is `ready` (with the container name), `waiting` (no free sandbox slot yet), `destroyed`, `closed` or `no_sandbox`. The sandbox is chosen by the listener's address, never by anything the client sends.
- `POST /internal/ssh/activity {"address"}` returns `{"state": "ok"}` and keeps the sandbox from being paused. The proxy sends it every 60 s while a connection is spliced.
- `POST /internal/ssh/authorized-keys {"fingerprint"}` returns `{"lines": ["restrict,port-forwarding,permitopen=\"127.18.4.17:22\" ssh-ed25519 AAAA..."]}`. `yak-ssh-lookup` (the jump sshd's `AuthorizedKeysCommand`) takes the fingerprint as `%f`, accepts only `^SHA256:[A-Za-z0-9+/]{43}$`, prints the lines, and prints nothing and exits 0 on any error, which means no login. It gives up after 3 s.

Plain text before the SSH banner: while a sandbox is `waiting`, the wake proxy writes `yak: waiting for a free sandbox\r\n` once, then asks again every 2 s until the timeout. RFC 4253 section 4.2 lets a server send lines that do not start with `SSH-` before its version string, so clients show them and carry on. `destroyed`, `closed`, `no_sandbox`, a timeout or an unreachable Yak write one `yak: ...` line and close.

When the state is `ready`, the proxy checks the container name against `^[a-z0-9][a-z0-9-]{0,62}$`, reads its global IPv4 address with `incus query /1.0/instances/<name>/state`, waits up to 20 s for port 22, then copies bytes both ways until either side closes.

## 9. Terminal bridge

`termbridge` (standard library only) serves `GET /terminal/ws?token=<one-time token>[&cols=N&rows=M]` on `YAK_TERMBRIDGE_LISTEN` and `GET /healthz`. It runs the real `ssh` client under a PTY with a fresh ed25519 key that never leaves a 0700 temp directory, and deletes the key when the session ends.

Environment: `YAK_SSH_URL`, `YAK_SSH_TOKEN_FILE` (as in section 8), `YAK_TERMBRIDGE_LISTEN` (default `127.0.0.1:8765`), `YAK_TERMBRIDGE_ORIGINS` (comma list, exact match against the `Origin` header), `SSH_BIN` (ssh binary, default `ssh` from `PATH`).

Gates, all before the upgrade and before any Yak call: method must be GET; the token must be 1 to 128 characters (400); `Origin` must be present and in the allowlist (403). After the upgrade the bridge generates the key and calls `POST /internal/ssh/terminal/redeem {token, public_key, origin}` with the bearer token; any non-200 closes with 4403.

Frames: binary frames carry raw terminal bytes in both directions. Text frames from the browser are JSON control messages: `{"type":"resize","cols":N,"rows":M}` (1 to 500 each) and `{"type":"ping"}`. When ssh exits the bridge sends the text frame `{"type":"exit","code":N}` and then closes with 1000. Client frames must be masked and are capped at 64 KiB.

Close codes: 1000 normal or ssh exited, 1001 absolute lifetime (12 hours) reached or bridge stopping, 1002 protocol error (unmasked frame, bad control frame), 1003 invalid control message, 1009 frame too large, 1011 bridge could not start the session, 4403 token denied, 4408 idle (30 minutes without frames), 4429 too many sessions (4 per thread, 64 overall).

The sandbox is kept awake by the wake proxy's activity calls (section 8) while the ssh connection is open; the bridge makes no activity calls of its own.
