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
| `start_runner` | `container`, `uid`, `cwd`, `start` (the runner `start` line, section 3) | Kills any runner left in the container, starts a new runner under `setsid`, writes `start` to its stdin, and increments the channel's generation. |
| `turn` | `uuid`, `text`, optional `priority` | Writes a runner `user` line. |
| `steer` | `uuid`, `text`, optional `priority` (`next` or `now`) | Same wire effect as `turn`; the driver tracks them differently. |
| `interrupt` | none | Writes an `interrupt` line. |
| `set_model` | `model` | Writes a `set_model` line. |
| `end` | none | Writes an `end` line: finish the current work and exit. |
| `kill` | none | Kills the runner's whole process tree with the full kill script. |
| `tool_result` | `call_id`, `is_error`, `content` | Writes the answer to a `yak.tool_call` (section 4) to the runner's stdin. |

The broker deletes every inbound entry after processing it, whether or not it parses. A `turn` or `steer` with no live runner produces a broker line `{"type":"broker","event":"no_runner","uuid":"..."}`.

### Outbound entries (`{channel}:out`)

Fields:

- `generation`: integer, starts at 1 and increments on every `start_runner`. A driver ignores lines of an older generation than the channel's current one.
- `sequence`: integer, monotonic per channel across generations. Handling is idempotent per sequence.
- `line`: one raw JSON line exactly as the runner wrote it, or a broker line.
- `origin`: `broker` for lines the broker wrote itself, empty for runner lines. Runner output cannot set this field.

Broker lines are objects with `"type":"broker"` and an `event`: `broker_started` (after a broker restart), `runner_exited` (with `generation` and `exit_code`; `-1` when the runner could not be started), `no_runner`.

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

## 5. Credential socket and auth proxy

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

Wiring: `NewAuthProxy(SocketCredentialSource{Path: socketPath}, upstreamURL, logger)`, then `Serve(ctx, listener)` on the bridge address. `Serve` shuts down gracefully on `ctx.Done` (10 s drain), uses a 10 s `ReadHeaderTimeout` and has no write timeout.
