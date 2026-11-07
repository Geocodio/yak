<?php

declare(strict_types=1);
use Cbox\Telemetry\Http\Middleware\AllowIps;
use Cbox\Telemetry\Support\HttpMethod;
use Cbox\Telemetry\Support\Redactor;

// Honor the OpenTelemetry-standard OTEL_EXPORTER_OTLP_HEADERS
// ("key1=val1,key2=val2") for interop. TELEMETRY_* values win over these.
$otelHeaders = [];
if (is_string($rawOtelHeaders = env('OTEL_EXPORTER_OTLP_HEADERS')) && $rawOtelHeaders !== '') {
    foreach (explode(',', $rawOtelHeaders) as $pair) {
        if (str_contains($pair, '=')) {
            [$hk, $hv] = explode('=', $pair, 2);

            if (($hk = trim($hk)) !== '') {
                $otelHeaders[$hk] = trim($hv);
            }
        }
    }
}

return [

    /*
    |--------------------------------------------------------------------------
    | Master Switch
    |--------------------------------------------------------------------------
    |
    | When disabled, every instrument resolves to a no-op, no event listeners
    | are registered, and no providers are booted. A disabled install adds no
    | measurable per-request overhead.
    |
    */

    'enabled' => env('TELEMETRY_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Service Resource
    |--------------------------------------------------------------------------
    |
    | Identifies this application on every exported signal, following the
    | OpenTelemetry resource semantic conventions.
    |
    */

    'service' => [
        'name' => env('TELEMETRY_SERVICE_NAME', env('OTEL_SERVICE_NAME', env('APP_NAME', 'laravel'))),
        'namespace' => env('TELEMETRY_SERVICE_NAMESPACE'),
        'version' => env('TELEMETRY_SERVICE_VERSION'),
        'environment' => env('TELEMETRY_SERVICE_ENVIRONMENT', env('APP_ENV', 'production')),

        // Deployment marker (git sha, release tag) — shows on every
        // signal so regressions map to deploys.
        'deployment' => env('TELEMETRY_SERVICE_DEPLOYMENT'),

        // service.instance.id — what Prometheus's and Mimir's OTLP
        // receivers turn into the `instance` label. Always set: unset
        // here, it is host.name for a store on this host (the same in
        // every FPM worker), or a fingerprint of a shared Redis (the same
        // on every host, since its one total must stay one series).
        'instance_id' => env('TELEMETRY_SERVICE_INSTANCE_ID'),
    ],

    // Auto-detect host/container/k8s/cloud resource attributes (host.name,
    // host.arch, container.id,
    // k8s.pod.name, k8s.namespace.name, cloud.region, …) from cgroup
    // facts (via cboxdk/system-metrics), well-known downward-API env vars
    // and OTEL_RESOURCE_ATTRIBUTES. Config service.* keys always win.
    'resource_detection' => env('TELEMETRY_RESOURCE_DETECTION', true),

    // Emit the package's own health as metrics (telemetry.export.*,
    // telemetry.spool.depth, telemetry.export.circuit_open,
    // telemetry.spool.circuit_open) — who watches
    // the watcher. Cheap; disable only if you truly don't want them.
    'self_metrics' => env('TELEMETRY_SELF_METRICS', true),

    /*
    |--------------------------------------------------------------------------
    | Metric Store
    |--------------------------------------------------------------------------
    |
    | Push instruments (counters, gauges, histograms) write to shared storage
    | so values survive PHP's shared-nothing lifecycle and aggregate across
    | web workers, queue workers and nodes.
    |
    | Supported drivers: "redis", "apcu", "sqlite", "array".
    |
    | We recommend a Redis connection separate from your queue connection.
    |
    | "sqlite" is for runtimes with neither Redis nor APCu — NativePHP
    | mobile and desktop. It is durable across restarts and safe for
    | several processes writing the same series. See
    | docs/decisions/0001-metric-state-on-single-process-runtimes.md.
    |
    */

    'store' => env('TELEMETRY_STORE', 'redis'),

    // Buffer metric writes in memory and flush them aggregated at request/
    // job terminate — 100 increments of one counter cost a single store
    // command. Trade-off: a hard crash loses the unflushed buffer.
    'buffer_writes' => env('TELEMETRY_BUFFER_WRITES', true),

    'stores' => [
        // WARNING: `php artisan cache:clear` is not prefix-aware — Laravel's
        // Redis cache store runs a raw FLUSHDB, and the apcu cache driver
        // calls apcu_clear_cache() (wipes the whole shared segment for
        // every worker). If this connection is the SAME Redis database (or
        // apcu is ALSO your cache driver), a routine cache:clear silently
        // erases every metric. `telemetry:doctor` checks for this — give
        // telemetry its own connection/database if it flags a collision.
        'redis' => [
            'connection' => env('TELEMETRY_REDIS_CONNECTION', 'default'),
            'prefix' => env('TELEMETRY_REDIS_PREFIX', 'telemetry'),

            // The last line of defence against a runaway label: the most
            // hash fields ONE metric family may hold before new series
            // are refused. Existing series keep being written, and the
            // refusals are counted into a `__overflow` field that
            // `telemetry:doctor` reports — a ceiling nobody is told
            // about is just missing data.
            //
            // A field is not a series: a histogram spends one per bucket
            // plus a sum and a count, so ~14 fields per series on the
            // default ladder. The default is far above any sane label
            // set and exists for the ones that are not. 0 disables it.
            'max_fields' => env('TELEMETRY_REDIS_MAX_FIELDS', 50000),
        ],

        'apcu' => [
            'prefix' => env('TELEMETRY_APCU_PREFIX', 'telemetry'),
        ],

        'sqlite' => [
            // Its own file, opened on a raw PDO handle — never one of the
            // app's database connections, or QueryInstrumentation would
            // measure telemetry's own writes.
            'path' => env('TELEMETRY_SQLITE_PATH', storage_path('framework/telemetry-metrics.sqlite')),

            // How long a write waits for a competing writer before giving
            // up. Only matters when more than one process writes — a
            // desktop app's queue worker alongside its server.
            'busy_timeout' => env('TELEMETRY_SQLITE_BUSY_TIMEOUT', 5000),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Exporters
    |--------------------------------------------------------------------------
    |
    | Each exporter declares which signals it supports. Traces and events are
    | flushed at request/job terminate; metrics are exported by the scheduled
    | `telemetry:flush` command (OTLP) or scraped on demand (Prometheus).
    |
    */

    'exporters' => env('TELEMETRY_EXPORTERS') === null
        ? []
        : explode(',', (string) env('TELEMETRY_EXPORTERS')),

    'otlp' => [
        'endpoint' => env('TELEMETRY_OTLP_ENDPOINT', env('OTEL_EXPORTER_OTLP_ENDPOINT', 'http://localhost:4318')),
        // Bearer token for an auth-gated OTLP endpoint (e.g. a shared
        // collector). Sent as `Authorization: Bearer <token>`. Additional
        // headers can come from the OTel-standard OTEL_EXPORTER_OTLP_HEADERS.
        'headers' => array_filter([
            'Authorization' => env('TELEMETRY_OTLP_TOKEN') ? 'Bearer '.env('TELEMETRY_OTLP_TOKEN') : null,
        ]) + $otelHeaders,
        'timeout' => env('TELEMETRY_OTLP_TIMEOUT', 3.0),
        'connect_timeout' => env('TELEMETRY_OTLP_CONNECT_TIMEOUT', 1.0),

        // gzip request bodies above 1 KB.
        'compression' => env('TELEMETRY_OTLP_COMPRESSION', true),

        // High-traffic mode: instead of POSTing at request terminate,
        // spans/events are pushed to a capped Redis list (one RPUSH per
        // request) and `telemetry:flush --daemon` ships them in merged
        // batches every --interval seconds. Drop-oldest above max_items.
        'spool' => [
            'enabled' => env('TELEMETRY_OTLP_SPOOL', false),

            // Also buffer metric snapshots. Success means buffered, not delivered.
            // Shares the capped spool and its lossy delivery semantics.
            'metrics' => env('TELEMETRY_OTLP_SPOOL_METRICS', false),

            // "redis" (a capped list) or "sqlite" (a file). Use sqlite
            // where there is no Redis and the network comes and goes —
            // NativePHP mobile and desktop — because it is the one that
            // survives the app being killed with a full queue.
            'driver' => env('TELEMETRY_OTLP_SPOOL_DRIVER', 'redis'),

            'connection' => env('TELEMETRY_OTLP_SPOOL_CONNECTION', 'default'),
            'key' => env('TELEMETRY_OTLP_SPOOL_KEY', 'telemetry:spool'),
            'max_items' => env('TELEMETRY_OTLP_SPOOL_MAX_ITEMS', 20000),

            // sqlite driver only. Its own file — sharing the metric store's
            // would make a spool drain contend with every metric write.
            'path' => env('TELEMETRY_OTLP_SPOOL_PATH', storage_path('framework/telemetry-spool.sqlite')),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Prometheus Scrape Endpoints
    |--------------------------------------------------------------------------
    |
    | Multiple named endpoints may be exposed, each with its own path,
    | middleware stack and metric filter — e.g. a public endpoint with a
    | narrow allowlist and an internal endpoint with everything.
    |
    */

    'prometheus' => [
        'enabled' => env('TELEMETRY_PROMETHEUS_ENABLED', true),

        'endpoints' => [
            'default' => [
                'path' => env('TELEMETRY_PROMETHEUS_PATH', 'telemetry/metrics'),
                'middleware' => [
                    AllowIps::class,
                ],
                // Only expose metrics whose name matches one of these
                // prefixes. Null exposes everything.
                'only' => null,
            ],
        ],

        // IPs (single or CIDR) allowed by the AllowIps middleware.
        'allowed_ips' => env('TELEMETRY_ALLOWED_IPS') === null
            ? []
            : explode(',', (string) env('TELEMETRY_ALLOWED_IPS')),

        // Bearer token accepted as an alternative to the IP allowlist —
        // Prometheus's own scrape_config supports `authorization.credentials`
        // natively, so a scraper that can't be IP-restricted can still
        // authenticate. Checked with hash_equals(), never logged.
        'token' => env('TELEMETRY_PROMETHEUS_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Traces
    |--------------------------------------------------------------------------
    |
    | Spans buffer in memory and flush once at terminate. The sample decision
    | is made per trace at the root span and propagated downstream via the
    | W3C traceparent sampled flag.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Directives
    |--------------------------------------------------------------------------
    |
    | Time-limited "keep every trace (and a profile) for this route / path /
    | user / tenant / job" rules: `php artisan telemetry:watch`,
    | Telemetry::directives(), or the signed endpoint below for a telemetry
    | UI or insights install elsewhere. Stored in the app's cache so every
    | host sees them. With no directives the per-request cost is one APCu
    | read (an empty list is remembered for `recheck_seconds`); without APCu
    | under PHP-FPM it is one cache read per request — set enabled=false if
    | that matters and you do not use directives.
    |
    */
    'directives' => [
        'enabled' => env('TELEMETRY_DIRECTIVES', true),
        'store' => env('TELEMETRY_DIRECTIVES_STORE'),
        'recheck_seconds' => 15,
        'max' => 50,
        'max_minutes' => 1440,

        // The signed HTTP endpoint exists only when a secret is set.
        'path' => 'telemetry/directives',
        'secret' => env('TELEMETRY_DIRECTIVES_SECRET'),
    ],

    'traces' => [
        'sample_rate' => env('TELEMETRY_TRACES_SAMPLE_RATE', 1.0),

        // Force-flush when the in-memory buffer exceeds this many spans
        // (protects long-running jobs and Octane workers).
        'max_buffer' => env('TELEMETRY_TRACES_MAX_BUFFER', 5000),

        // And the same in bytes, because five thousand spans is a
        // sensible ceiling for ordinary spans and no ceiling at all for
        // spans carrying a 100KB query or a serialized payload. Whole
        // spans are dropped past it, counted as telemetry.spans.dropped
        // — never a truncated value, which would cut a credential in
        // half before redaction could recognise it. 0 disables it.
        'max_buffer_bytes' => env('TELEMETRY_TRACES_MAX_BUFFER_BYTES', 33554432),

        // Error spans are exported even from unsampled traces — a
        // 10%-sampled app still surfaces every failing span.
        'always_sample_errors' => env('TELEMETRY_TRACES_ALWAYS_SAMPLE_ERRORS', true),

        // Publish trace_id into Laravel's Context facade at trace start —
        // Sentry (>= 4.x), Flare and every log channel pick it up
        // automatically, closing the loop: error tracker -> trace id ->
        // Tempo waterfall. An explicit Sentry scope tag is also set when
        // the SDK is installed.
        'share_context' => env('TELEMETRY_TRACES_SHARE_CONTEXT', true),

        // Expose the trace id on every response — the support-case
        // reference ("quote id X to support") and the Tempo lookup key.
        // Set to null/empty to disable.
        'response_header' => env('TELEMETRY_TRACES_RESPONSE_HEADER', 'X-Trace-Id'),

        // W3C `traceresponse` header (trace id + server span id) on the
        // same responses — lets telemetry-browser parent requests after an
        // Inertia visit to the span that served it. Sent only when
        // `response_header` is on: switching that off means no trace ids.
        'traceresponse' => env('TELEMETRY_TRACES_TRACERESPONSE', true),

        // `Server-Timing` header: db / cache / redis time and counts, the
        // package's own overhead and the time so far — shown by browser
        // DevTools, read by telemetry-browser. Off by default: it tells
        // the client how long the database took.
        'server_timing' => env('TELEMETRY_TRACES_SERVER_TIMING', false),

        // Trust incoming `traceparent` headers and continue remote traces.
        'continue_incoming' => env('TELEMETRY_TRACES_CONTINUE_INCOMING', true),

        // Parent the request span to the incoming traceparent's span id.
        // Disable behind an edge (load balancer, proxy) that injects
        // traceparent so its access log joins your trace, but exports no
        // span of its own: every request then hangs under a span that never
        // arrives, and trace backends list it as missing its root. Off, the
        // trace id is still adopted (the edge's log still joins), the
        // request span is the root, and the edge's span id becomes a link.
        'continue_incoming_parent' => env('TELEMETRY_TRACES_CONTINUE_INCOMING_PARENT', true),

        // Request header naming a request for before/after comparison
        // (`X-Telemetry-Label: before-eager-load`). Becomes a context
        // dimension, `laravel.trace.label`, so every span of the request
        // and the jobs it dispatches carry it (and `withTraceparent()`
        // sends it on as baggage). Cut to 64 safe characters; never a
        // metric label. Null disables it.
        'label_header' => env('TELEMETRY_TRACES_LABEL_HEADER', 'X-Telemetry-Label'),

        // Children started through the Process facade get `TRACEPARENT` in
        // their environment (OpenTelemetry's carrier for process → process),
        // and an artisan command started with it set continues that trace
        // as a child instead of opening a root of its own. The receiving
        // side needs `instrument.commands` on to record anything.
        'propagate_to_processes' => env('TELEMETRY_TRACES_PROPAGATE_TO_PROCESSES', true),
        'continue_from_environment' => env('TELEMETRY_TRACES_CONTINUE_FROM_ENVIRONMENT', true),

        // Tail detail retention: keep detail spans (cache ops, queries)
        // only for traces with errors or slowness — MANY details when it
        // hurts, a lean skeleton + aggregates when all is well. The
        // decision is made at flush, when the whole trace is in memory.
        'details' => [
            'mode' => env('TELEMETRY_TRACES_DETAILS', 'always'), // always | tail
            'slow_request_ms' => env('TELEMETRY_TRACES_SLOW_REQUEST_MS', 1000),
            'slow_span_ms' => env('TELEMETRY_TRACES_SLOW_SPAN_MS', 100),

            // Your own reasons to keep detail in tail mode. Each rule is a
            // map of attribute => pattern(s) that must all match ('name'
            // is the span name, * and ? allowed); any matching rule wins.
            // keep_when: a matching span keeps its whole trace's detail.
            //   ['http.route' => ['checkout*', 'api/orders*']],
            //   ['gen_ai.operation.name' => 'invoke_agent'],
            //   ['tenant.id' => '7'],
            'keep_when' => [],
            // keep_spans: a matching detail span is kept in every trace.
            //   ['db.query.text' => ['insert *', 'update *', 'delete *']],
            'keep_spans' => [],
        ],

        // Also trust the caller's SAMPLING decision. Disable on public
        // edges so clients cannot force sampling on (bypassing your
        // sample rate) or off (hiding from tracing); trace ids are still
        // continued for correlation.
        'trust_incoming_sampling' => env('TELEMETRY_TRACES_TRUST_INCOMING_SAMPLING', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Events
    |--------------------------------------------------------------------------
    */

    'events' => [
        // Force-flush when this many events are buffered (protects
        // long-running workers using the telemetry log channel).
        'max_buffer' => env('TELEMETRY_EVENTS_MAX_BUFFER', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Histogram Buckets
    |--------------------------------------------------------------------------
    |
    | Default bucket boundaries for histograms that don't declare their own.
    | Values are in the instrument's native unit.
    |
    | The ladder tops out at 10 seconds, which suits a request and suits
    | nothing that runs for minutes. A classic histogram reports the
    | highest finite boundary for anything past it, so a queue an hour
    | behind answers p95 = 10 and an alert set at 30 never fires — silently
    | and forever. Long-running instruments pass their own ladders; do the
    | same for yours.
    |
    */

    // Seconds, per the semantic conventions: "When instruments are
    // measuring durations, seconds (i.e. `s`) SHOULD be used." Every
    // histogram that falls back to these is a duration.
    'default_buckets' => [0.001, 0.005, 0.01, 0.025, 0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10],

    /*
    |--------------------------------------------------------------------------
    | Redaction Engine
    |--------------------------------------------------------------------------
    |
    | Every span attribute, span event (exception messages included) and
    | telemetry event passes through the redaction engine at flush time,
    | before any exporter sees it.
    |
    | `keys` match whole dot/underscore segments of attribute keys — the
    | value is replaced entirely. `patterns` are regexes scrubbing secrets
    | embedded in any string value (JWTs, Bearer/Basic credentials, url
    | userinfo by default). Omit a key to keep the built-in defaults; see
    | Redactor::defaultKeys() / defaultPatterns(). Add a custom last-pass
    | hook with Telemetry::redactUsing().
    |
    */

    'redaction' => [
        'enabled' => env('TELEMETRY_REDACTION', true),

        // The built-in lists, BY REFERENCE rather than by copy.
        //
        // Publishing this file used to freeze them: mergeConfigFrom() is a
        // shallow merge, so a published block replaced the package's whole and
        // a copied list meant a package that learned a new credential spelling
        // could never tell you. Referencing the accessors keeps upstream
        // additions flowing, and as a second belt the package lists are
        // UNIONED in at runtime, so an older published copy is still covered.
        //
        // Append your own by spreading:
        //
        //   'keys'     => [...Redactor::defaultKeys(), 'cpr'],
        //   'patterns' => [...Redactor::defaultPatterns(), '/\d{6}-\d{4}/' => '[REDACTED]'],
        //   'safe_keys'=> [...Redactor::defaultSafeKeys(), 'my.known_safe.bucket'],
        //
        // Set replace_defaults to take the lists over outright — nothing is
        // unioned in, and what you write here is the whole of it. Only do that
        // if a built-in entry actively gets in your way; `safe_keys` and the
        // custom hook are the gentler tools for that.
        'replace_defaults' => env('TELEMETRY_REDACTION_REPLACE_DEFAULTS', false),

        'keys' => Redactor::defaultKeys(),

        'patterns' => Redactor::defaultPatterns(),

        'safe_keys' => Redactor::defaultSafeKeys(),

        'replacement' => '[REDACTED]',

        // Auto-instrumentation needs more than a name list.
        //
        // For a context value you set yourself, a list of key names is
        // enough — you chose the key. The URLs this package records are
        // not yours: they belong to whatever third-party API the
        // application calls, and no list enumerates `?t=`, `?sas=` or
        // whatever the next vendor decides to call its token.
        //
        // So a query-parameter value is ALSO judged by its shape, when
        // its name said nothing.

        // Issuer prefixes: unambiguous, free to check, and the reason
        // every one of these vendors stamps its keys in the first place.
        // Unioned with the package's list unless replace_defaults.
        'credential_prefixes' => Redactor::defaultCredentialPrefixes(),

        // The generic rule: a value this long, made only of token
        // characters, with lower AND upper AND digits, is a generated
        // credential and not a slug, an email, a date or a path. UUIDs
        // are excluded by shape — a UUID in a URL is an id somebody
        // needs to read.
        //
        // Set false to judge values by prefix only. Raise the length if
        // your own identifiers trip it; lower it at your peril, because
        // the false positives start immediately below here.
        'value_shape' => env('TELEMETRY_REDACTION_VALUE_SHAPE', true),
        'value_min_length' => env('TELEMETRY_REDACTION_VALUE_MIN_LENGTH', 24),

        // Structured personal identifiers, found in free text. OFF by
        // default, because it costs something per value and because
        // removing data an operator needed is its own failure.
        //
        // This is a defence-in-depth CONTROL, not compliance. It catches
        // identifiers that leaked in by accident — an email in an
        // exception message, a card number in a SQL string, a CPR in a
        // URL path — which is how personal data actually reaches an
        // observability pipeline; nobody decides to put it there. No
        // configuration of it makes an application GDPR-, HIPAA- or
        // SOC 2-compliant: HIPAA names eighteen identifiers and this
        // sees six, because a person's name, their address and a
        // medical record number have no form to recognise. Those need
        // the key rules above and the discipline not to put them in a
        // span.
        //
        // Every detector that can be checksummed is: a sixteen-digit
        // number is an order id far more often than a card, and without
        // Luhn this would redact the primary key of every table in the
        // application.
        //
        //   'pii' => true                              // the defaults
        //   'pii' => ['detectors' => ['email', 'ip']]  // exactly these
        //
        // Available: email, credit_card, iban, us_ssn, dk_cpr, ip, phone.
        // `ip` and `phone` are not in the defaults — an IP is also how
        // you find the one host that is broken, and a bare phone number
        // is indistinguishable from an order id.
        'pii' => env('TELEMETRY_REDACTION_PII', false),

        // The longest attribute value that reaches an exporter, in
        // bytes. 0 disables the cap.
        //
        // This is the only place in the pipeline where a value is
        // bounded at all — Span::setAttribute stores what it is given —
        // so without it an application that puts a megabyte in an
        // attribute sends a megabyte, at a collector that will probably
        // reject the batch. It also bounds what the patterns above have
        // to scan: several of them scale worse than linearly on a
        // pathological value, and an exception message can contain
        // whatever a user typed.
        //
        // The cut is marked, because a silently truncated value looks
        // like a complete one and somebody will debug against a string
        // that ends in the middle of the answer.
        'max_value_length' => env('TELEMETRY_REDACTION_MAX_VALUE_LENGTH', 4096),
    ],

    /*
    |--------------------------------------------------------------------------
    | Browser / Frontend Span Ingest (optional)
    |--------------------------------------------------------------------------
    |
    | An opt-in endpoint the browser posts its own spans to (page load,
    | fetch timings, JS errors). When the browser also propagates its W3C
    | traceparent to your backend, browser and backend spans share one
    | trace id — end-to-end distributed tracing in a single waterfall.
    |
    | A browser endpoint cannot hold a secret, so it is protected by
    | throttling, strict payload bounding and optional head sampling —
    | never a bearer token. Add your own auth via `middleware` if the app
    | is behind login.
    |
    */

    'ingest' => [
        'spans' => [
            'enabled' => env('TELEMETRY_INGEST_SPANS', false),
            'path' => env('TELEMETRY_INGEST_SPANS_PATH', 'telemetry/spans'),
            // Flood defenses (a browser endpoint is world-reachable).
            'middleware' => ['throttle:300,1'],
            'max_spans' => 128,          // per batch; excess dropped
            'max_attributes' => 32,      // per span
            'sample_rate' => 1.0,        // head sampling; drop a fraction of batches

            // Where the vendored @cboxdk/telemetry-browser bundle
            // (@telemetryBrowser) is served from.
            'asset_path' => env('TELEMETRY_INGEST_SPANS_ASSET', 'telemetry/browser.js'),
            'browser' => [
                'fetch' => true,          // fetch spans + traceparent to same-origin calls
                'xhr' => true,            // the same for XMLHttpRequest (axios)
                'errors' => true,         // uncaught errors + unhandled rejections as exception spans
                'vitals' => true,         // Core Web Vitals (LCP, CLS, INP, FCP, TTFB) as browser.web_vital spans
                'navigation' => true,     // history route changes as browser.route_change spans
                'frameworks' => true,     // Livewire wire:navigate / Inertia visits as browser.navigation spans
                'server_timing' => true,  // read the Server-Timing header onto page-load + fetch/XHR spans
                'sample' => 1.0,          // client-side head sampling (0-1)
                'debug' => false,         // console.debug the SDK's activity
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Source Maps (optional — for symbolicating browser stacks)
    |--------------------------------------------------------------------------
    |
    | An upload endpoint the build pipeline POSTs source maps to (via the
    | @cboxdk/telemetry-browser uploader), keyed by release. telemetry-ui
    | then resolves minified browser frames back to the original source.
    | Uploads come from CI, so this is bearer-token gated (set a token) —
    | it is never accidentally open.
    |
    */

    'sourcemaps' => [
        'enabled' => env('TELEMETRY_SOURCEMAPS', false),
        'token' => env('TELEMETRY_SOURCEMAPS_TOKEN'),
        'path' => env('TELEMETRY_SOURCEMAPS_PATH', 'telemetry/sourcemaps'),
        'disk' => env('TELEMETRY_SOURCEMAPS_DISK', 'local'),
        'prefix' => 'telemetry/sourcemaps',
        'middleware' => [],
        'max_bytes' => 20 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Analytics (opt-in, default OFF)
    |--------------------------------------------------------------------------
    |
    | An additive analytics layer on top of the telemetry we already collect
    | — a shared session.id across browser + server, so visits (not just
    | single traces) can be analysed. Everything here is off by default and
    | changes NOTHING when disabled. The identity and geo resolution are
    | fully overridable via hooks, so you can source them from Cloudflare
    | headers (CF-Connecting-IP, CF-IPCountry, CF-Ray), a first-party
    | cookie, or your own logic — see Telemetry::resolveSessionUsing() and
    | Telemetry::resolveClientGeoUsing().
    |
    */

    'analytics' => [
        'enabled' => env('TELEMETRY_ANALYTICS', false),

        // Emit an unsampled `analytics.page_view` event per top-level
        // document load — the count that must never be undersampled.
        'page_views' => env('TELEMETRY_ANALYTICS_PAGE_VIEWS', true),

        'session' => [
            // Cookieless by default: the built-in session.id is a
            // daily-rotating, salted hash so a raw IP never becomes a
            // grouping key. Override the whole thing with a hook for exact,
            // cookie-based visits.
            'salt' => env('TELEMETRY_ANALYTICS_SALT'),
        ],

        // Country from the client, resolved at collection time so the raw IP
        // can be dropped afterwards. Precedence: a resolveClientGeoUsing()
        // hook wins, then Cloudflare's CF-IPCountry edge header (free, no
        // database), then an OPTIONAL MaxMind database (composer suggest:
        // geoip2/geoip2). Applies to both the server page view and the
        // browser ingest endpoint.
        'geo' => [
            'enabled' => env('TELEMETRY_ANALYTICS_GEO', false),

            // Prefer Cloudflare's CF-IPCountry header (ISO country, every
            // plan) over a MaxMind lookup. Only trusted when the request
            // arrives through a trusted proxy — CF-* headers are spoofable on
            // a directly-reachable origin. Set Laravel's TrustProxies to the
            // immediate hop the app sees: the Cloudflare ranges if CF connects
            // directly, or your load balancer in a CF -> LB -> app chain (the
            // LB, not the CF ranges). A safe no-op otherwise.
            'cloudflare' => env('TELEMETRY_ANALYTICS_GEO_CF', true),

            'database' => env('TELEMETRY_ANALYTICS_GEO_DB'),
        ],

        // Parse user_agent.original into low-cardinality user_agent.name /
        // os.name / device.type (dependency-free, families only, never
        // versions). Off by default — leave the raw UA for query-time if you
        // prefer.
        'user_agent' => env('TELEMETRY_ANALYTICS_UA', false),

        // Capture campaign attribution from the landing URL's query: utm_source/
        // medium/campaign/content/term as analytics.utm.*, plus a low-cardinality
        // analytics.click_id (the paid ad-network param NAME — gclid/msclkid/… — never
        // its unbounded value). utm_content/term are higher cardinality than the rest;
        // on a Loki backend keep an eye on campaign volume (see the cardinality note).
        'utm' => env('TELEMETRY_ANALYTICS_UTM', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Automatic Instrumentation
    |--------------------------------------------------------------------------
    */

    'instrument' => [
        // HTTP server spans + http.server.* metrics via global middleware.
        'requests' => env('TELEMETRY_INSTRUMENT_REQUESTS', true),

        // Request paths left completely uninstrumented: no server span, no
        // http.server.* metrics, no analytics page view — and no trace for
        // anything that runs inside the request (its query, cache, outgoing
        // HTTP and mail spans are context only, never exported, not even on
        // error). Exceptions reported there are still recorded, without a
        // trace id. Str::is() globs on the path WITHOUT its leading slash:
        // 'health', 'horizon*', 'telemetry-ui/*' ('/' is the site root).
        //
        // Packages add their own with Telemetry::ignorePaths([...]); both
        // lists apply. Env: comma-separated, e.g. "health,up,horizon*".
        //
        // A health probe is the usual first candidate — Laravel's /up
        // answers an uptime check every few seconds and tells you nothing
        // about your app. It is NOT excluded by default: it is your route,
        // and silently dropping traffic an installation already records is
        // worse than the noise. Add 'up' here when you want it gone.
        'http_ignore_paths' => env('TELEMETRY_HTTP_IGNORE_PATHS') === null
            ? []
            : array_values(array_filter(array_map('trim', explode(',', (string) env('TELEMETRY_HTTP_IGNORE_PATHS'))))),

        // This package's OWN routes — the Prometheus scrape endpoints, the
        // browser span ingest, the RUM asset, the source map upload — are
        // ignored like any other ignored path. A 15-second scrape is ~5,700
        // requests a day measuring nothing about the app, and the ingest
        // route fires once per real page view: telemetry reporting itself
        // as traffic. Prometheus already times its own scrapes
        // (scrape_duration_seconds), from the side that can act on it.
        // Follows whatever path each route is configured with.
        'http_ignore_own_routes' => env('TELEMETRY_HTTP_IGNORE_OWN_ROUTES', true),

        // Add the domain as a server.address label on http.server.*
        // metrics. Routes with a domain pattern report the PATTERN
        // ("{tenant}.app.example"), keeping wildcard-tenant cardinality
        // bounded.
        //
        // Without a pattern the concrete host is only used when it comes
        // from a FINITE set the application named: app.url, the list in
        // `hosts` below, or a trusted-host pattern with no wildcard in
        // it. Anything else reports "other", because $request->getHost()
        // is otherwise just the caller's Host header and a label must
        // never be caller-controlled.
        //
        // Trusted hosts alone are not enough, which is worth saying
        // plainly: they prove the host was VALIDATED, not that there are
        // few of them. Laravel's own TrustHosts defaults to every
        // subdomain of app.url, and an app behind wildcard DNS would
        // have minted a permanent series per subdomain anyone asked for.
        'host_label' => env('TELEMETRY_INSTRUMENT_HOST_LABEL', true),

        // Hosts kept as-is in server.address, for a multi-domain app
        // whose domains cannot be enumerated in TrustHosts. The list is
        // the application's, which is the whole point — every host not
        // on it (and not covered by the rules above) reports "other".
        'hosts' => [],

        // Methods reported as themselves on http.request.method; anything
        // else becomes "_OTHER", with the real one kept on the span as
        // http.request.method_original.
        //
        // The method is whatever the caller put on the request line, and a
        // server span is started for unmatched requests too — so without a
        // bound it is a metric dimension anyone can grow from outside your
        // app. The default is the list semconv names. Add to it if you serve
        // methods it does not cover (WebDAV's PROPFIND, MKCOL, REPORT) and
        // want them broken out rather than bucketed.
        'known_http_methods' => HttpMethod::SEMCONV,

        // Request headers captured on the span as http.request.header.*
        // (allowlist, lowercase). Credentials and session headers
        // (Authorization, Cookie, X-Api-Key, …) are denylisted and never
        // captured, even if listed here.
        'request_headers' => ['accept', 'accept-language', 'content-type', 'origin', 'referer', 'x-forwarded-for', 'x-requested-with'],

        // Response headers captured as http.response.header.*.
        'response_headers' => ['content-type', 'cache-control'],

        // Job spans + queue metrics, with trace continuation from dispatch.
        'jobs' => env('TELEMETRY_INSTRUMENT_JOBS', true),

        // OTel span links between a retried job's attempts — a link,
        // never a parent, since attempt N+1 is a sibling of attempt N
        // (both children of the original dispatch), not a continuation.
        // Bridged via the app's own cache (queue.retry_link_store/_ttl)
        // since a retry can land on a different worker process; a
        // null/array cache driver just means retries go unlinked.
        'queue_retry_links' => env('TELEMETRY_INSTRUMENT_QUEUE_RETRY_LINKS', true),

        // db.client.* query spans (only recorded inside a sampled trace).
        'queries' => env('TELEMETRY_INSTRUMENT_QUERIES', true),

        // Skip query spans faster than this (ms) — a noise floor for
        // N+1-heavy codepaths. 0 records everything.
        'queries_min_duration' => env('TELEMETRY_INSTRUMENT_QUERIES_MIN_DURATION', 0),

        // Flag queries that repeat within one trace, as one of two smells:
        //  - duplicate: same SQL, same values (memoize or cache it)
        //  - N+1: same SQL shape, different values (eager load / whereIn)
        // Each gives a root-span tally, a counter and an OTLP log event
        // carrying the query text and its origin, fired once per distinct
        // query when it crosses the threshold. Repeated query spans are
        // marked `laravel.db.query.repeat`.
        'query_duplicates' => env('TELEMETRY_INSTRUMENT_QUERY_DUPLICATES', true),
        'query_duplicates_threshold' => env('TELEMETRY_INSTRUMENT_QUERY_DUPLICATES_THRESHOLD', 3),

        // Where in the app a query came from (code.file.path,
        // code.line.number, code.function.name — first non-vendor frame).
        // 'flagged' = slow (traces.details.slow_span_ms) and repeated
        // queries only; 'all' = every query span (a backtrace per query);
        // 'off' = never.
        'query_origin' => env('TELEMETRY_INSTRUMENT_QUERY_ORIGIN', 'flagged'),

        // Read Laravel's own Context (`Context::add('tenant', …)`) onto root
        // spans, log records and exception records as `laravel.context.*`.
        // Opt-in: Context is whatever the app keeps there. Hidden context
        // is never read; never a metric label. `laravel_context_keys`
        // limits it to the listed keys (empty = all, max 32 per record).
        'laravel_context' => env('TELEMETRY_INSTRUMENT_LARAVEL_CONTEXT', false),
        'laravel_context_keys' => [],

        // Artisan command spans.
        'commands' => env('TELEMETRY_INSTRUMENT_COMMANDS', false),

        // Tag request spans with the authenticated user's id (user.id)
        // for per-user trace filtering. Id only — never name/email.
        'user' => env('TELEMETRY_INSTRUMENT_USER', true),

        // Peak memory + CPU time per request and per queue job — span
        // attributes (php.memory.peak_bytes, php.cpu.time_ms) and
        // histograms (http.server.memory.peak / cpu.time, queue.job.*).
        'resources' => env('TELEMETRY_INSTRUMENT_RESOURCES', true),

        // Whether to also take the OS process footprint (real RSS and
        // CPU utilization) from cboxdk/system-metrics, on top of the PHP
        // built-ins that `resources` always provides.
        //
        // null = decide by platform, and the platform matters enormously:
        // on Linux this reads /proc/{pid}/stat and costs microseconds;
        // everywhere else the library shells out to `ps`, which is ~28ms
        // — twice per request and twice per job. On a developer's Mac
        // that made this package the dominant cost of every request,
        // fifty-six milliseconds to measure something that took two.
        //
        // Set true to take it anyway, false to never take it. When it is
        // skipped, rssPeakBytes and cpuUtilization are simply absent,
        // exactly as when system-metrics is not installed.
        'resources_process' => env('TELEMETRY_INSTRUMENT_RESOURCES_PROCESS'),

        // Scheduled task spans + schedule.tasks.{processed,failed,skipped}
        // counters and schedule.task.duration histogram.
        'scheduled_tasks' => env('TELEMETRY_INSTRUMENT_SCHEDULED_TASKS', true),

        // Missed / late / overrunning scheduled tasks: every schedule:run
        // publishes when each task was last due, every start or skip when
        // it was last seen — gauges the bundled alert rules compare. Needs
        // a metric store shared by the hosts that run the scheduler.
        'schedule_monitoring' => env('TELEMETRY_INSTRUMENT_SCHEDULE_MONITORING', true),

        // CPU profiling via ext-excimer (PECL, not bundled) — batteries
        // included: enabled by default, a silent no-op without the
        // extension. Profiling always runs (sampling overhead is low),
        // but the result is only kept for requests/jobs slower than
        // `profiling.min_duration_ms` — a "top functions by sample
        // count" event, not a full pprof export.
        'profiling' => env('TELEMETRY_INSTRUMENT_PROFILING', true),

        // cache.operations{operation, store} counters (hit/miss/write/
        // forget). Off by default — hot caches are chatty.
        'cache' => env('TELEMETRY_INSTRUMENT_CACHE', false),

        // Trace-timeline spans per cache operation — key,
        // store and duration on every hit/miss/write/forget in the
        // trace waterfall. Keys are safe on spans (per-occurrence, not
        // aggregated). Off by default; the span buffer cap bounds
        // pathological requests.
        'cache_spans' => env('TELEMETRY_INSTRUMENT_CACHE_SPANS', false),

        // Authentication lifecycle: auth.events{event, guard} counters —
        // login/logout/failed/lockout/password_reset/registered/verified.
        // A spike in `failed` is a credential attack in progress.
        'auth' => env('TELEMETRY_INSTRUMENT_AUTH', true),

        // Database transaction spans (BEGIN..COMMIT/ROLLBACK, nested via
        // savepoints) + db.transactions.rolled_back counter.
        'transactions' => env('TELEMETRY_INSTRUMENT_TRANSACTIONS', true),

        // Eloquent: model.hydrations root-span tally (the N+1 smell) +
        // models.events{model,event} write counters + models.pruned.
        'models' => env('TELEMETRY_INSTRUMENT_MODELS', true),

        // Job batch lifecycle counters: bus.batches{event, name}.
        'batches' => env('TELEMETRY_INSTRUMENT_BATCHES', true),

        // Redis command spans + redis.commands counter. Off by default —
        // high volume. The package's own connections are always ignored
        // (self-instrumentation would loop): the metric store's when the
        // store is redis, the spool's when a redis spool is on. Add your
        // own with redis_ignore_connections; it is unioned with those.
        'redis' => env('TELEMETRY_INSTRUMENT_REDIS', false),
        'redis_ignore_connections' => null,

        // redis.commands.failed counter + an errored client span per Redis
        // command that raised. On by default, and separate from `redis`
        // above, because the two answer different questions: per-command
        // spans are for profiling Redis, failures are for finding out that
        // Redis is why everything else is failing. That signal has to be
        // on before the incident, not switched on during it.
        //
        // Laravel dispatches CommandExecuted and CommandFailed from one
        // switch, so this does cost an event dispatch per Redis command
        // even when nothing fails — the listener is a single in_array and
        // a return. Set to false on a hot path that cannot afford it.
        'redis_failures' => env('TELEMETRY_INSTRUMENT_REDIS_FAILURES', true),

        // db.connect / redis.connect spans + the
        // db.client.connection.create_time histogram.
        //
        // Laravel's query and command events fire only once a connection is
        // already up, so the handshake — DNS, TCP, TLS, auth — is invisible
        // to them. It is also where a healthy-looking app spends its worst
        // seconds: a database answering every query in a millisecond still
        // hangs for thirty if the connect blocks. Without these, that wait
        // is an unexplained gap in the waterfall before the first query.
        //
        // Cheap: PDO is resolved lazily, so this fires at most once per
        // connection per request, not once per query. The spans are NOT
        // detail-marked — a connect is exactly what you want kept when a
        // slow trace is trimmed.
        //
        // redis_connect honours redis_ignore_connections and always skips
        // the package's own connections (the redis store's, a redis
        // spool's). phpredis is timed
        // in the connector; predis connects on the first command, so each
        // node connection times itself then — under Sentinel that is one
        // span for the sentinel asked and one for the master it named. A
        // connection that sets its own predis `connections` factory is
        // left alone.
        'db_connect' => env('TELEMETRY_INSTRUMENT_DB_CONNECT', true),
        'redis_connect' => env('TELEMETRY_INSTRUMENT_REDIS_CONNECT', true),

        // Application lifecycle, not request path. Each fires a handful of
        // times a day and each explains a chart that would otherwise only
        // raise questions.
        //
        // migrations       db.migration.duration histogram +
        //                  db.migrations.started / db.migration.ran /
        //                  db.migrations.finished events. A schema change
        //                  is the most common answer to "what changed at
        //                  14:32", and a migration that holds a lock for
        //                  four minutes is invisible without the histogram.
        // maintenance_mode app.maintenance_mode events, so a planned
        //                  window reads as a window and not an outage.
        // connection_pool  db.connections.over_threshold counter + event,
        //                  from Laravel's DatabaseBusy: the server's own
        //                  connection count crossed the --max somebody
        //                  gave db:monitor. Not a failed checkout, and not
        //                  a client pool — but the count climbing is the
        //                  difference between "the database is slow" and
        //                  "everyone is queuing to talk to it", which have
        //                  opposite fixes and identical symptoms.
        'migrations' => env('TELEMETRY_INSTRUMENT_MIGRATIONS', true),
        'maintenance_mode' => env('TELEMETRY_INSTRUMENT_MAINTENANCE_MODE', true),
        'connection_pool' => env('TELEMETRY_INSTRUMENT_CONNECTION_POOL', true),

        // Gate/policy checks: authorization.checks{ability, result}
        // counter + gate.check.count / gate.denied.count root-span
        // tallies. Ability names are code identifiers (bounded).
        'gates' => env('TELEMETRY_INSTRUMENT_GATES', true),

        // A span per Blade/PHP view render — templates, partials and
        // components, naturally nested with real durations. Detail-marked
        // (tail mode trims them from healthy fast traces). The root span
        // carries a view.render.count tally regardless.
        'views' => env('TELEMETRY_INSTRUMENT_VIEWS', true),

        // session.driver + session.hash (sha256-truncated, NEVER the raw
        // id) on request root spans — one TraceQL query follows a whole
        // visitor journey across requests.
        'session' => env('TELEMETRY_INSTRUMENT_SESSION', true),

        // Cache stores that are never recorded (neither counters nor
        // spans) — e.g. a store used exclusively by a framework
        // subsystem you instrument separately. For key-level control,
        // see Telemetry::classifyCacheKeysUsing().
        'cache_ignore_stores' => [],

        // mail.send spans + mail.sent / mail.failed counters.
        'mail' => env('TELEMETRY_INSTRUMENT_MAIL', true),

        // Record who each message went to on its mail.send span — mail.to,
        // mail.cc and mail.bcc, comma-separated — so a send can be found
        // by recipient ("every request that mailed x@example.com"). OFF by
        // default: addresses are personal data, kept as long as your
        // traces are. redaction.pii's email detector redacts them again at
        // flush, so turn that detector off (or this on) — not both.
        'mail_recipients' => env('TELEMETRY_INSTRUMENT_MAIL_RECIPIENTS', false),

        // notification.send spans + notifications.sent{channel,notification}.
        'notifications' => env('TELEMETRY_INSTRUMENT_NOTIFICATIONS', true),

        // Outgoing Http-client spans + http.client.request.duration
        // histogram by host/method/status.
        'http_client' => env('TELEMETRY_INSTRUMENT_HTTP_CLIENT', true),

        // Flag an N+1 in outgoing calls: one endpoint SHAPE (method, host,
        // path with ids taken out, query parameter names) called with
        // different values, one call after another, `threshold` times in
        // one trace — a per-row fetch in a loop. Reads (GET/HEAD) only;
        // pagination (?page=, ?cursor=, …) and calls sent together through
        // Http::pool are not N+1. Gives an `http.client.n_plus_one.count`
        // root-span tally, an `http.client.requests.n_plus_one` counter and
        // an `http.client.n_plus_one_detected` OTLP log event with the
        // origin, fired once per shape per trace. Client spans carry
        // `url.template` and `laravel.http.client.shape`; spans from the
        // crossing on carry `laravel.http.client.repeat`.
        'http_client_n_plus_one' => env('TELEMETRY_INSTRUMENT_HTTP_CLIENT_N_PLUS_ONE', true),
        'http_client_n_plus_one_threshold' => env('TELEMETRY_INSTRUMENT_HTTP_CLIENT_N_PLUS_ONE_THRESHOLD', 3),

        // Where in the app an outgoing call was made (code.file.path,
        // code.line.number, code.function.name). 'flagged' = the call that
        // crosses the N+1 threshold; 'all' = every client span (a
        // backtrace per call); 'off' = never.
        'http_client_origin' => env('TELEMETRY_INSTRUMENT_HTTP_CLIENT_ORIGIN', 'flagged'),

        // Break a client span's duration into its phases: DNS, TCP, the TLS
        // handshake, time-to-first-byte and the body coming back down, plus
        // the peer address, port and negotiated protocol version. Answers
        // which of them a slow call was actually slow in.
        //
        // Nearly free to collect: Laravel's HTTP client already installs
        // cURL's on_stats callback and keeps the result on the response, so
        // this only reads what is there — about a microsecond per call. The
        // cost that is worth weighing is the other end: up to NINE more
        // attributes per client span to store, and to run redaction over at
        // flush. Nothing is recorded when the handler is not cURL — a faked
        // response, or the stream handler.
        'http_client_timing' => env('TELEMETRY_INSTRUMENT_HTTP_CLIENT_TIMING', true),

        // exceptions.reported counter — includes HANDLED exceptions that
        // report() swallows.
        'exceptions' => env('TELEMETRY_INSTRUMENT_EXCEPTIONS', true),

        // Attach the source lines around the throw site to exception
        // records (exception.source) — the "feels like Sentry" detail.
        // Off by default: it reads the source file on every reported
        // exception.
        'exception_source' => env('TELEMETRY_INSTRUMENT_EXCEPTION_SOURCE', false),

        // The following auto-activate when the package is installed
        // (class_exists-guarded) — never a hard dependency.

        // feature.checks{feature,result} + feature.unknown{feature} via
        // laravel/pennant's own events.
        'pennant' => env('TELEMETRY_INSTRUMENT_PENNANT', true),

        // Supervisor/master state, long-wait detection, process restarts
        // and OOM via laravel/horizon's own events. Job-level tracing
        // already works without this — Horizon workers fire the standard
        // queue events QueueInstrumentation listens to.
        'horizon' => env('TELEMETRY_INSTRUMENT_HORIZON', true),

        // reverb.messages{direction}, reverb.channels{event,type} and
        // reverb.connections.pruned via laravel/reverb's own events.
        // Channel names and connection ids are never used as labels.
        'reverb' => env('TELEMETRY_INSTRUMENT_REVERB', true),

        // OpenTelemetry GenAI spans and metrics for laravel/ai (the
        // official Laravel AI SDK) via its own events: invoke_agent
        // {agent} → chat {model} / execute_tool {tool} per run,
        // embeddings {model}, plus gen_ai.client.operation.duration and
        // gen_ai.client.token.usage{gen_ai.token.type}. Labels are the
        // operation, provider and requested model — never prompts.
        'ai' => env('TELEMETRY_INSTRUMENT_AI', true),

        // Record prompts, completions, system instructions and tool
        // arguments/results on those spans (gen_ai.input.messages,
        // gen_ai.output.messages, gen_ai.system_instructions,
        // gen_ai.tool.call.arguments/result). OFF by default, as the
        // conventions ask: this is user data. When on, every value still
        // goes through the redaction engine at flush (patterns,
        // Telemetry::redactUsing(), redaction.max_value_length).
        'ai_content' => env('TELEMETRY_INSTRUMENT_AI_CONTENT', false),

        // Byte budget for one captured content attribute, shared across
        // the messages it carries and cut on UTF-8 boundaries so the JSON
        // stays valid. Keep it under redaction.max_value_length (4096 by
        // default), which caps the whole attribute after redaction.
        'ai_content_max_bytes' => env('TELEMETRY_INSTRUMENT_AI_CONTENT_MAX_BYTES', 2048),

        // Your model prices, per 1M tokens, so each model call carries
        // gen_ai.usage.cost and adds to the gen_ai.client.cost counter
        // (same labels as the token metric). No catalog ships with the
        // package: an unpriced model gets no cost, never a guess. A key is
        // a model, optionally prefixed by its provider (the laravel/ai
        // driver or gen_ai.provider.name), and may use * for snapshots;
        // an exact key wins over a pattern, a longer pattern over a
        // shorter one. cache_read / cache_write fall back to input.
        //   'models' => [
        //       'anthropic/claude-sonnet-*' => ['input' => 3.00, 'output' => 15.00, 'cache_read' => 0.30, 'cache_write' => 3.75],
        //       'openai/text-embedding-3-small' => ['input' => 0.02],
        //   ],
        'ai_pricing' => [
            'currency' => env('TELEMETRY_AI_CURRENCY', 'USD'),
            'models' => [],
        ],

        // Telemetry::context() keys added as labels on the gen_ai.client.*
        // metrics, for exact totals per plan, region or tier. BOUNDED
        // values only: never a user, team or tenant id — every value is a
        // series per model. Per-id totals belong in ai_usage_events.
        //   'ai_metric_labels' => ['plan'],
        'ai_metric_labels' => [],

        // One gen_ai.client.inference.operation.details log record per
        // model call: the call's gen_ai.* attributes (tokens, cost, models,
        // finish reason — never content), user.id, your Telemetry::context()
        // dimensions and error.type. Log records are never sampled, so
        // spend per user, team or tenant summed in the log backend is
        // exact. Off by default: one record per call.
        'ai_usage_events' => env('TELEMETRY_INSTRUMENT_AI_USAGE_EVENTS', false),

        // inertia.request span attribute (from the X-Inertia header) +
        // inertia.version_mismatches counter — a version mismatch means
        // Inertia is forcing a full page reload (X-Inertia-Location),
        // the noisy signal right after a deploy. Pure response
        // inspection, no inertiajs/inertia-laravel dependency needed.
        'inertia' => env('TELEMETRY_INSTRUMENT_INERTIA', true),

        // rate_limit.exceeded{limiter} counter from a 429 response — the
        // driver-agnostic signal (Laravel's RateLimiter fires no event).
        // Labeled by the `throttle:<name>` route middleware's limiter
        // name when present, "default" for an inline throttle:60,1 spec.
        'rate_limiting' => env('TELEMETRY_INSTRUMENT_RATE_LIMITING', true),

        // Inherit the caller's Telemetry::context() dimensions (team,
        // tenant, plan, …) from an incoming W3C `baggage` header — the
        // sibling of traceparent. Gated on traces.continue_incoming too:
        // baggage is caller-supplied, unvalidated data, so it follows
        // the same trust boundary as continuing the trace itself.
        // Outgoing: Http::withTraceparent() attaches both headers.
        'baggage' => env('TELEMETRY_INSTRUMENT_BAGGAGE', true),

        // Livewire component lifecycle via laravel/livewire's own
        // ComponentHook API. mount/hydrate have no "after" phase in that
        // API (our hook is one peer listener, not a wrapper) so they're
        // counters (livewire.components.mounted/hydrated); render/update/
        // call DO wrap the real work, so those get detail spans
        // (livewire.render/update/call) — same tail-sampled, root-span-
        // tallied shape as view rendering.
        'livewire' => env('TELEMETRY_INSTRUMENT_LIVEWIRE', true),

        // NativePHP for Mobile screens. Two halves: screen.views +
        // .view.duration come free from the upstream Screen lifecycle
        // events on nativephp/mobile ^4.1 (guarded by class_exists, so
        // 4.0.x never arms them), while screen.interaction/native_event
        // spans need the
        // app to forward dispatch()/dispatchNativeEvent() from a
        // NativeComponent base class — upstream announces no interaction,
        // and that is where the flush must happen. See
        // docs/cookbook/nativephp.md. Turning this off silences both and
        // makes the forwards pass straight through, which is how a consent
        // prompt answered mid-session takes effect without a restart.
        'native_screens' => env('TELEMETRY_INSTRUMENT_NATIVE_SCREENS', true),

        // broadcast.count root-span tally + a "broadcast {event}" detail
        // span per Broadcaster::broadcast() call — driver-agnostic
        // (Pusher, Ably, Reverb, Redis, Log, …). Reverb's own richer
        // connection/channel occupancy metrics (instrument.reverb) are
        // separate and unaffected by this toggle.
        'broadcasting' => env('TELEMETRY_INSTRUMENT_BROADCASTING', true),

        // storage.operations{disk,operation} counter + a "storage
        // {operation}" detail span per disk operation (put, get,
        // delete, copy, move, …) — driver-agnostic (local, S3, whatever
        // Flysystem supports). Paths are safe on spans (per-occurrence)
        // but never metric labels — same rule as query text.
        'filesystem' => env('TELEMETRY_INSTRUMENT_FILESYSTEM', true),

        // Disks left entirely uninstrumented, by name. An instrumented
        // disk is a FilesystemAdapter, so it satisfies everything that
        // type-hints Laravel's concrete adapter — but PHP cannot pick a
        // parent class at runtime, so it can never BE an adapter
        // subclass (AwsS3V3Adapter, or a package's own). List a disk here
        // if something type-hints the exact subclass and breaks.
        'filesystem_ignore_disks' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue Trace Propagation
    |--------------------------------------------------------------------------
    |
    | Dispatched jobs carry the full W3C traceparent (trace id AND parent
    | span id) so job spans appear as children of the dispatch site.
    |
    */

    'queue' => [
        'propagate' => env('TELEMETRY_QUEUE_PROPAGATE', true),

        // Where the retry-link side channel lives (instrument.
        // queue_retry_links) — the app's own cache, NOT the telemetry
        // metric store. null uses the app's default cache store.
        'retry_link_store' => env('TELEMETRY_QUEUE_RETRY_LINK_STORE'),
        'retry_link_ttl' => env('TELEMETRY_QUEUE_RETRY_LINK_TTL', 86400),

        // Queued work that starts a NEW trace, linked to the dispatching
        // span, instead of continuing it as a child — for long-delayed or
        // fan-out jobs that would otherwise make one giant trace. The same
        // as implementing Contracts\StartsLinkedTrace, for classes you
        // cannot edit: jobs, queued listeners, mailables, notifications.
        // A parent class or interface matches everything under it.
        'linked_traces' => [
            // \App\Jobs\RebuildSearchIndex::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Host & Process Monitor
    |--------------------------------------------------------------------------
    |
    | The optional node_exporter analog (`telemetry:monitor`). Cron mode:
    | Schedule::command('telemetry:monitor --once')->everyMinute() — or a
    | daemon under supervisor. Foreign long-running processes are found
    | by pgrep pattern and sampled for aggregate RSS + count.
    |
    */

    'monitor' => [
        'interval' => env('TELEMETRY_MONITOR_INTERVAL', 15),

        'processes' => [
            // 'queue-workers' => 'queue:work',
            // 'horizon' => 'horizon',
            // 'reverb' => 'reverb:start',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | CPU Profiling (ext-excimer)
    |--------------------------------------------------------------------------
    |
    | Statistical sampling profiler for slow requests/jobs. Requires the
    | PECL `excimer` extension — a silent no-op without it, same as
    | cboxdk/system-metrics.
    |
    */

    'profiling' => [
        // Sampling interval — lower catches more detail, costs more.
        'period' => env('TELEMETRY_PROFILING_PERIOD', 0.001),

        // Only keep/report a profile for requests/jobs at least this
        // slow — tail-based, matches traces.details.slow_request_ms.
        'min_duration_ms' => env('TELEMETRY_PROFILING_MIN_DURATION_MS', 500),

        // Top functions by sample count kept in the profile event.
        'top_functions' => env('TELEMETRY_PROFILING_TOP_FUNCTIONS', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Native Runtime (cboxdk/telemetry-native)
    |--------------------------------------------------------------------------
    |
    | The optional `cbox_telemetry` extension measures what PHP cannot
    | measure about itself: which call stacks burned the CPU, how long
    | opening a connection actually took, and what the process was doing
    | when it died on a fatal signal. A silent no-op without it.
    |
    |     pie install cboxdk/telemetry-native
    |
    | It owns no semantics and never touches the network — naming,
    | sampling policy, redaction and export all stay here.
    |
    */

    'native' => [
        // Master switch. Off means no unit of work is ever opened, whether
        // the extension is loaded or not.
        'enabled' => env('TELEMETRY_NATIVE', true),

        // Use the native profiler for request/job CPU profiles. It replaces
        // ext-excimer where both are installed: two samplers running at once
        // measure each other. Also gated by instrument.profiling, and the
        // tail threshold is the shared profiling.min_duration_ms.
        'profile' => env('TELEMETRY_NATIVE_PROFILE', true),

        // Sampling period in microseconds and stack depth per sample. Null
        // leaves the extension's own INI values (1000 µs / 64 frames) alone.
        //
        // Asking for less than the kernel can deliver buys nothing: Linux
        // evaluates POSIX CPU timers on the scheduler tick, so on a HZ=250
        // kernel anything under 4 ms is reported back as skipped ticks in
        // profile.timer_overruns.
        'period_us' => env('TELEMETRY_NATIVE_PERIOD_US'),
        'max_depth' => env('TELEMETRY_NATIVE_MAX_DEPTH'),

        // The full call tree alongside the top functions, as flat
        // (parent, frame, samples) triples plus the frame table they refer
        // to. Off by default: it is one to two orders of magnitude more
        // data per slow request than the top-function list.
        'stacks' => env('TELEMETRY_NATIVE_STACKS', false),
        'max_stack_nodes' => env('TELEMETRY_NATIVE_MAX_STACK_NODES', 2048),

        // Native operation timing (pdo.connect, redis.connect, curl.exec)
        // as span attributes, plus the runtime.operations counter and
        // runtime.operation.duration histogram.
        'operations' => env('TELEMETRY_NATIVE_OPERATIONS', true),

        // php.gc.runs / php.gc.collected on the unit span and as counters.
        'counters' => env('TELEMETRY_NATIVE_COUNTERS', true),

        // Drain crash records in telemetry:flush and report them as
        // FATAL-severity crash.recorded events, correlated to the trace the
        // process died in. Draining consumes, so exactly one collector
        // should run — telemetry:crashes is the manual one.
        'crashes' => env('TELEMETRY_NATIVE_CRASHES', true),
        'crash_max' => env('TELEMETRY_NATIVE_CRASH_MAX', 32),
        'crash_breadcrumbs' => env('TELEMETRY_NATIVE_CRASH_BREADCRUMBS', 32),

        // Commands that own their process and host their own units of work.
        // A native unit here would span hours and swallow every job or task
        // inside it, so these open none and leave the boundary to what runs
        // within them. Matched with Str::is(), so `*` works.
        'exclude_commands' => [
            'queue:work',
            'queue:listen',
            'horizon*',
            'octane:*',
            'schedule:run',
            'schedule:work',
            'reverb:*',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Built-in Providers
    |--------------------------------------------------------------------------
    */

    'providers' => [
        // Host CPU / memory / load metrics via cboxdk/system-metrics
        // (auto-enabled when the package is installed).
        'system' => [
            'enabled' => env('TELEMETRY_SYSTEM_METRICS', true),

            // Sampling interval for CPU utilization at collect time.
            // Set to 0 to skip CPU utilization entirely.
            'cpu_interval' => env('TELEMETRY_SYSTEM_CPU_INTERVAL', 0.1),
        ],
    ],

];
