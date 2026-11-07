<?php

declare(strict_types=1);
use Cbox\TelemetryUi\Panels\Builtin\DeploysTimeline;
use Cbox\TelemetryUi\Panels\Builtin\ExceptionsOverview;
use Cbox\TelemetryUi\Panels\Builtin\JobsOverview;
use Cbox\TelemetryUi\Panels\Builtin\RequestDuration;
use Cbox\TelemetryUi\Panels\Builtin\RequestsActivity;
use Cbox\TelemetryUi\Panels\Builtin\RoutesNeedingAttention;

return [

    /*
    |--------------------------------------------------------------------------
    | Enabled
    |--------------------------------------------------------------------------
    |
    | Master switch. When disabled the package registers no routes at all —
    | no SPA, no API, no assets — making it completely inert (e.g. in queue
    | workers or environments where the dashboard should not exist).
    |
    */

    'enabled' => (bool) env('TELEMETRY_UI_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    |
    | The path prefix and optional domain the dashboard is served from.
    | Note: the default deliberately avoids "telemetry" to not clash with
    | the /telemetry/metrics Prometheus scrape endpoint registered by
    | cboxdk/laravel-telemetry.
    |
    */

    'path' => env('TELEMETRY_UI_PATH', 'telemetry-ui'),

    'domain' => env('TELEMETRY_UI_DOMAIN'),

    /*
    |--------------------------------------------------------------------------
    | Scope lock (tenancy)
    |--------------------------------------------------------------------------
    |
    | Constrain the whole dashboard to a fixed set of services / environments —
    | every query is forced into them and the scope picker only offers them (a
    | dimension with a single forced value hides its picker entirely). This is
    | the static, no-code path; for a per-user/tenant lock use the dynamic
    | TelemetryUi::restrictScopeUsing() hook, which takes precedence when set.
    |
    | Per dimension: null (or absent) = not locked; a list = locked to exactly
    | those values; an empty list [] = locked to nothing (matches nothing —
    | fail closed). Env vars take a comma-separated list.
    |
    */

    'scope' => [
        'lock' => [
            'services' => filled(env('TELEMETRY_UI_LOCK_SERVICES'))
                ? array_values(array_filter(array_map('trim', explode(',', (string) env('TELEMETRY_UI_LOCK_SERVICES')))))
                : null,
            'environments' => filled(env('TELEMETRY_UI_LOCK_ENVIRONMENTS'))
                ? array_values(array_filter(array_map('trim', explode(',', (string) env('TELEMETRY_UI_LOCK_ENVIRONMENTS')))))
                : null,
            // Context dimensions a viewer is confined to, e.g.
            // ['tenant.id' => ['7']]. Governs monitors only (see "Monitors").
            // Usually returned per user by TelemetryUi::restrictScopeUsing().
            'contexts' => null,
        ],

        /*
         * What service, environment and host are called in each backend. The
         * defaults are what cboxdk/laravel-telemetry emits over OTLP. Change
         * them when the telemetry comes from elsewhere: a Prometheus/Alloy
         * scrape that stamps `environment` and `hostname` as external labels,
         * or an eBPF agent (Beyla) that sends the older `deployment.environment`
         * resource attribute. The lock above filters by these names, so a
         * wrong one makes every scoped query match nothing.
         */
        'labels' => [
            'metrics' => [
                'service' => env('TELEMETRY_UI_METRICS_SERVICE_LABEL', 'service_name'),
                'environment' => env('TELEMETRY_UI_METRICS_ENVIRONMENT_LABEL', 'deployment_environment_name'),
                'host' => env('TELEMETRY_UI_METRICS_HOST_LABEL', 'host_name'),
            ],
            'traces' => [
                'service' => env('TELEMETRY_UI_TRACES_SERVICE_ATTRIBUTE', 'resource.service.name'),
                'environment' => env('TELEMETRY_UI_TRACES_ENVIRONMENT_ATTRIBUTE', 'resource.deployment.environment.name'),
                'host' => env('TELEMETRY_UI_TRACES_HOST_ATTRIBUTE', 'resource.host.name'),
            ],
            'logs' => [
                'service' => env('TELEMETRY_UI_LOGS_SERVICE_LABEL', 'service_name'),
                'environment' => env('TELEMETRY_UI_LOGS_ENVIRONMENT_LABEL', 'deployment_environment_name'),
                'host' => env('TELEMETRY_UI_LOGS_HOST_LABEL', 'host_name'),
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | View state
    |--------------------------------------------------------------------------
    |
    | The reader's time window, auto-refresh interval and service/environment
    | scope, remembered in a cookie so they survive a reload, a link that
    | carries no query string, and a drill into a trace and back. An explicit
    | URL parameter always wins, and taking one updates what is remembered —
    | so shared deep links keep showing the sender's view.
    |
    | The cookie is a preference, not a credential: it is readable by the page's
    | own JavaScript (the auto-refresh control writes it) and grants nothing —
    | a remembered scope is still bounded by the tenancy lock above and every
    | query is forced into that lock regardless. Set "enabled" to false to go
    | back to URL-or-default with nothing carried between requests.
    |
    */

    'state' => [
        'enabled' => (bool) env('TELEMETRY_UI_STATE', true),
        'cookie' => env('TELEMETRY_UI_STATE_COOKIE', 'telemetry_ui_view'),
        // Minutes. A year: this is a view preference, not a session.
        'lifetime' => (int) env('TELEMETRY_UI_STATE_LIFETIME', 60 * 24 * 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | Analytics
    |--------------------------------------------------------------------------
    |
    | Which visitor dimensions the "Sources & audience" breakdown surfaces.
    | Turning one off HIDES its column — it does NOT reduce ingest cardinality:
    | that is controlled in the emitter (telemetry.analytics.*), because the cost
    | is paid when an attribute becomes a Loki stream label, not when the UI
    | reads it. See the cardinality guide in docs/cookbook/analytics.md.
    |
    | Each dimension also needs its emitter capture flag on to carry any data:
    |   country / region / city → telemetry.analytics.geo.*
    |   device / os / browser   → telemetry.analytics.user_agent
    |   referrer / source        → always on
    |
    | Cardinality per dimension (Loki stream-label cost): referrer, source,
    | country, device, os, browser are LOW; region is MEDIUM; city is HIGH — so
    | city defaults OFF. Enable the high-cardinality ones only at low traffic, or
    | once a ClickHouse sink sits behind these cards.
    |
    */

    'analytics' => [
        // Referrer hosts that are your own site: a self-referral (your domain
        // linking to itself) then classifies as the "Internal" channel instead
        // of polluting "Referral". Subdomains count. Comma-separated env, or an
        // array here. Empty = off (self-referrals stay "Referral").
        'internal_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('TELEMETRY_UI_INTERNAL_HOSTS', ''))
        ))),

        'dimensions' => [
            // Derived at read time from the referrer (+ UTM/click-id once the
            // emitter captures them) — low-cardinality, no ingest cost.
            'channels' => true,
            'referrers' => true,
            'sources' => true,
            'countries' => true,
            'regions' => true,
            'cities' => false,
            'devices' => true,
            'os' => true,
            'browsers' => true,
            // Campaign attribution (needs the emitter's telemetry.analytics.utm).
            // content/term are higher-cardinality than campaign/source/medium.
            'campaigns' => true,
            'utm_sources' => true,
            'utm_mediums' => true,
            'utm_contents' => false,
            'utm_terms' => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | Middleware applied to all dashboard routes. The package always appends
    | its own Authorize middleware, which checks the "viewTelemetryUi" gate.
    | Out of the box the gate only allows access in the local environment;
    | define the gate in your app to open it up elsewhere.
    |
    */

    'middleware' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Copy link
    |--------------------------------------------------------------------------
    |
    | The header button that copies a shareable URL for the current view.
    |
    | Turn it off where the URL cannot travel. A desktop host serves the
    | dashboard from 127.0.0.1 on a port it picked, so the link it copies only
    | ever resolves on that machine, while that app is running — a button
    | offering to share something unshareable.
    |
    */

    'copy_link' => env('TELEMETRY_UI_COPY_LINK', true),

    /*
    |--------------------------------------------------------------------------
    | Timezone
    |--------------------------------------------------------------------------
    |
    | The zone the dashboard shows times in until a viewer picks their own in
    | the header. Null (the default) is each viewer's browser zone, so times
    | read as local time. Set an IANA name ('UTC', 'Europe/Copenhagen') to
    | give everyone the same clock, for a team spread across zones.
    |
    */

    'timezone' => env('TELEMETRY_UI_TIMEZONE'),

    /*
    |--------------------------------------------------------------------------
    | Ignore the dashboard's own requests
    |--------------------------------------------------------------------------
    |
    | Every panel, Explore query and page load is an HTTP request to the host
    | app. The dashboard asks cboxdk/laravel-telemetry (ignorePaths()) not to
    | trace its own path, so it never drowns the app's real traffic. Set to
    | false to trace the dashboard like any route.
    |
    */

    'ignore_own_requests' => (bool) env('TELEMETRY_UI_IGNORE_OWN_REQUESTS', true),

    /*
    |--------------------------------------------------------------------------
    | Dimension names
    |--------------------------------------------------------------------------
    |
    | How long a name resolved by TelemetryUi::resolve() (or a dimension's
    | `resolve:` callback) is cached per value, in seconds. 0 disables caching.
    |
    */

    'dimensions' => [
        'label_ttl' => (int) env('TELEMETRY_UI_LABEL_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Branding
    |--------------------------------------------------------------------------
    |
    | White-label the dashboard when embedding it in your own product: the
    | sidebar name/logo and the accent colour. `name` defaults to your app
    | name; `logo` is an image URL (shown before the name); `accent` is any CSS
    | colour and overrides the accent. There are no views to override in v2:
    | for deeper changes, read the JSON API and build your own surface.
    |
    | `credit` is the small "Powered by Cbox" link at the foot of the main nav.
    | Turn it off for a fully white-labelled dashboard.
    |
    */

    'brand' => [
        'name' => env('TELEMETRY_UI_BRAND_NAME'),
        'logo' => env('TELEMETRY_UI_BRAND_LOGO'),
        'accent' => env('TELEMETRY_UI_BRAND_ACCENT'),
        'credit' => (bool) env('TELEMETRY_UI_BRAND_CREDIT', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Throttle
    |--------------------------------------------------------------------------
    |
    | Rate limit for the dashboard routes, as "maxAttempts,decayMinutes".
    | The SPA fetches every panel, facet list and Explore query as its own
    | small API request (and again on each auto-refresh tick), so the budget
    | is per request, not per page. This caps how hard a single client can
    | drive the backends. Set to null to disable.
    |
    */

    'throttle' => env('TELEMETRY_UI_THROTTLE', '600,1'),

    /*
    |--------------------------------------------------------------------------
    | Live tail (SSE)
    |--------------------------------------------------------------------------
    |
    | The log / request live tail streams over Server-Sent Events. The backends
    | are pull-only, so the stream polls them every `interval` seconds and each
    | connection lives `window` seconds before the browser reconnects (resuming
    | from the last event) — a tail never pins a PHP worker indefinitely.
    |
    */

    'stream' => [
        'interval' => (int) env('TELEMETRY_UI_STREAM_INTERVAL', 2),
        'window' => (int) env('TELEMETRY_UI_STREAM_WINDOW', 25),
    ],

    /*
    |--------------------------------------------------------------------------
    | Query cache & retries
    |--------------------------------------------------------------------------
    |
    | Every card issues live backend queries on each render and auto-refresh
    | tick. "cache.ttl" caches decoded GET responses (plain arrays, safe on
    | any store) for that many seconds so a busy dashboard with many cards and
    | concurrent viewers does not hammer Prometheus/Tempo/Loki. Keep it short
    | (a few seconds) so data stays fresh; 0 disables. A connection may set its
    | own "cache" to override. "retries" retries transient connection blips.
    |
    */

    'cache' => [
        'ttl' => (int) env('TELEMETRY_UI_CACHE_TTL', 5),
    ],

    'retries' => (int) env('TELEMETRY_UI_RETRIES', 2),

    /*
    | How many of one panel's backend reads go out at the same time. A page
    | loads its panels at the same time already, and a backend queues what it
    | cannot run side by side: on a single-node Mimir without query sharding,
    | more than 2 made a long window's heavy queries slow each other down
    | until one timed out. Raise it for a backend that shards queries. 1
    | sends them one after the other, as before.
    */
    'concurrency' => (int) env('TELEMETRY_UI_CONCURRENCY', 2),

    /*
    |--------------------------------------------------------------------------
    | Connections
    |--------------------------------------------------------------------------
    |
    | Named connections to your observability backends. The keys "metrics",
    | "traces" and "logs" are the defaults used when no explicit connection
    | name is requested; additional named connections may be added and
    | requested explicitly (e.g. a per-tenant Mimir connection).
    |
    | Drivers: prometheus, mimir (metrics) — tempo (traces) — loki (logs).
    | Mimir is the Prometheus API served under a path prefix (default
    | "/prometheus") with multi-tenancy via the X-Scope-OrgID header, which
    | Tempo and Loki honour as well when "tenant" is set.
    |
    | Auth: set "token" for a Bearer token (e.g. a Grafana service account,
    | when querying through Grafana's datasource proxy) or "basic_auth" as
    | "user:pass"; both are turned into an Authorization header. Add any other
    | headers under "headers".
    |
    | TLS: "verify" is true (default), a path to a custom CA bundle for an
    | internal PKI or self-signed certificate, or false to skip verification.
    | Only an explicit boolean false disables it — a stringy "false" from an env
    | var is read as a (nonexistent) CA path and fails closed, rather than
    | silently downgrading TLS on a typo. Skipping verification makes the
    | connection trivially interceptable; it is never a default.
    |
    | Grafana datasource proxy recipe (no direct backend access needed):
    |   URL  = https://grafana.example.com/api/datasources/proxy/uid/<uid>
    |   token = <grafana service-account token, Viewer role>
    | The Loki proxy expects the driver's own "/loki/..." path on top of the
    | proxy base, which this package already sends.
    |
    */

    'connections' => [

        'metrics' => [
            'driver' => env('TELEMETRY_UI_METRICS_DRIVER', 'prometheus'),
            'url' => env('TELEMETRY_UI_METRICS_URL', 'http://localhost:9090'),
            'prefix' => env('TELEMETRY_UI_METRICS_PREFIX'),
            'tenant' => env('TELEMETRY_UI_METRICS_TENANT'),
            'token' => env('TELEMETRY_UI_METRICS_TOKEN', env('TELEMETRY_UI_TOKEN')),
            'basic_auth' => env('TELEMETRY_UI_METRICS_BASIC_AUTH'),
            'verify' => env('TELEMETRY_UI_METRICS_CA_BUNDLE', true),
            'headers' => [],
            'timeout' => (float) env('TELEMETRY_UI_METRICS_TIMEOUT', 10.0),
            // Window-wide reads (a day's p95 across every route) longer than
            // this many seconds run as a range query of slices. Mimir needs
            // it — its frontend splits range queries by day, while an instant
            // query over the window is what hits max_fetched_chunks_per_query
            // — and it is on for the `mimir` driver (6h) unless set here;
            // Prometheus does better without, so it is off there (0). Set it
            // if a `prometheus`-driver URL points at Mimir.
            'split_after' => env('TELEMETRY_UI_METRICS_SPLIT_AFTER'),
            'split_slice' => (int) env('TELEMETRY_UI_METRICS_SPLIT_SLICE', 3600),
        ],

        'traces' => [
            'driver' => 'tempo',
            'url' => env('TELEMETRY_UI_TEMPO_URL', 'http://localhost:3200'),
            'tenant' => env('TELEMETRY_UI_TEMPO_TENANT'),
            'token' => env('TELEMETRY_UI_TEMPO_TOKEN', env('TELEMETRY_UI_TOKEN')),
            'basic_auth' => env('TELEMETRY_UI_TEMPO_BASIC_AUTH'),
            'verify' => env('TELEMETRY_UI_TEMPO_CA_BUNDLE', true),
            'headers' => [],
            'timeout' => (float) env('TELEMETRY_UI_TEMPO_TIMEOUT', 10.0),
            // The longest window one search covers, in seconds: Tempo's
            // query_frontend.search.max_duration (168h unless changed). A
            // longer window searches its newest part and says so. 0: no limit.
            'max_search' => (int) env('TELEMETRY_UI_TEMPO_MAX_SEARCH', 604_800),
            // How many matching spans a search returns per trace when it reads
            // the spans themselves (a row per mail, per query). Tempo's own
            // default is 3, which cut a trace with ten sends to three rows.
            'spans_per_trace' => (int) env('TELEMETRY_UI_TEMPO_SPANS_PER_TRACE', 100),
        ],

        'logs' => [
            'driver' => 'loki',
            'url' => env('TELEMETRY_UI_LOKI_URL', 'http://localhost:3100'),
            'tenant' => env('TELEMETRY_UI_LOKI_TENANT'),
            'token' => env('TELEMETRY_UI_LOKI_TOKEN', env('TELEMETRY_UI_TOKEN')),
            'basic_auth' => env('TELEMETRY_UI_LOKI_BASIC_AUTH'),
            'verify' => env('TELEMETRY_UI_LOKI_CA_BUNDLE', true),
            'headers' => [],
            'timeout' => (float) env('TELEMETRY_UI_LOKI_TIMEOUT', 10.0),
        ],

        // Optional issue tracker (GitHub, Sentry, Linear, …). When a driver is
        // set, an "Issues" page appears in the sidebar. Disabled by default.
        //
        // For several repos in one project (frontend, api, sidecar, …) make
        // this a LIST of connections instead — each with an optional "label":
        //   'issues' => [
        //       ['driver' => 'github', 'repo' => 'acme/frontend', 'token' => '…', 'label' => 'frontend'],
        //       ['driver' => 'github', 'repo' => 'acme/api',      'token' => '…', 'label' => 'api'],
        //   ],
        // The Issues page aggregates them, tagged by label, with a repo filter.
        'issues' => [
            'driver' => env('TELEMETRY_UI_ISSUES_DRIVER'),
            'repo' => env('TELEMETRY_UI_GITHUB_REPO'),
            'url' => env('TELEMETRY_UI_ISSUES_URL'),
            'token' => env('TELEMETRY_UI_ISSUES_TOKEN'),
            'timeout' => (float) env('TELEMETRY_UI_ISSUES_TIMEOUT', 10.0),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Schema detection
    |--------------------------------------------------------------------------
    |
    | Pages registered with a detection pattern (e.g. the built-in Statamic
    | page, which lights up when statamic_* metrics exist) probe the metrics
    | backend with one cached query. The result is cached for this many
    | seconds in your default cache store.
    |
    */

    'detection' => [
        'ttl' => (int) env('TELEMETRY_UI_DETECTION_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Fleet discovery
    |--------------------------------------------------------------------------
    |
    | The sidebar service/environment switcher lists the services and
    | deployment environments discovered from the metrics backend. That
    | label-value lookup is cached for this many seconds.
    |
    | With "All services" selected, log reads ask only for the logs of those
    | services, not for every stream the log store holds: a shared Loki also
    | keeps load balancer, host and container logs, often far more than the
    | apps write. The machine logs panel still reads every stream. Set
    | `scopes_logs` to false to read every stream everywhere.
    | When discovery finds no services, log reads are not narrowed.
    |
    */

    'fleet' => [
        'ttl' => (int) env('TELEMETRY_UI_FLEET_TTL', 60),
        'scopes_logs' => (bool) env('TELEMETRY_UI_FLEET_SCOPES_LOGS', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Hosts table
    |--------------------------------------------------------------------------
    |
    | The Hosts page's CPU and memory columns read the OTel host metrics
    | (system_cpu_utilization, system_memory_utilization) by default. A fleet
    | that runs node_exporter instead sets a PromQL query per column, one series
    | per host, labelled by `host_label` (default: the metrics host label).
    | `{environment}` expands to the viewer's environments as an alternation,
    | so use it with `=~`:
    |
    |   'cpu' => '1 - avg by (nodename) (rate(node_cpu_seconds_total{mode="idle",environment=~"{environment}"}[5m]))',
    |   'host_label' => 'nodename',
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Opening a trace
    |--------------------------------------------------------------------------
    |
    | A trace opened from a list carries when it happened, and a trace store
    | that can search by time (Tempo) is asked about the blocks within this many
    | seconds of it only, instead of every block in retention. Tempo matches a
    | block by its own time range, not the trace's, so keep this generous: at
    | ten minutes, lookups of traces that exist came back 404 on a busy store;
    | at an hour they were all found, in half the time of a full lookup. A miss
    | falls back to the full lookup either way.
    |
    | "diagnostics" sets what the trace diagnosis (the `trace_summary`,
    | `compare_traces` and `list_traces` MCP tools) flags as a warning: a
    | query at or over `slow_query_ms`; telemetry overhead of at least
    | `overhead_ms` that is also `overhead_ratio` of the trace; and, on traces
    | from before laravel-telemetry 3.5 (which marks N+1s itself), a statement repeated
    | `repeat_threshold` times (match the emitter's
    | `query_duplicates_threshold`).
    |
    | "waterfall.group_threshold" is how many consecutive identical sibling
    | spans (same query shape, same cache operation and key group, else the
    | same name) the trace waterfall's condensed layout folds into one row.
    | Below 2 turns grouping off; the reader can always switch to the full
    | layout.
    |
    */

    'traces' => [
        'lookup_window' => (int) env('TELEMETRY_UI_TRACE_LOOKUP_WINDOW', 3600),
        'diagnostics' => [
            'slow_query_ms' => (float) env('TELEMETRY_UI_SLOW_QUERY_MS', 100),
            'overhead_ms' => (float) env('TELEMETRY_UI_OVERHEAD_MS', 5),
            'overhead_ratio' => (float) env('TELEMETRY_UI_OVERHEAD_RATIO', 0.05),
            'repeat_threshold' => (int) env('TELEMETRY_UI_REPEAT_THRESHOLD', 3),
        ],
        'waterfall' => [
            'group_threshold' => (int) env('TELEMETRY_UI_WATERFALL_GROUP_THRESHOLD', 3),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Monitors
    |--------------------------------------------------------------------------
    |
    | A monitor is a cboxdk/laravel-telemetry directive (3.5+): for a while,
    | keep every trace of one route, path, user, job or context value, with its
    | detail spans and optionally a CPU profile. Spans recorded because of one
    | carry `telemetry.directive`.
    |
    | This app is monitorable in-process when it runs the core ("local").
    | Another app is reached through its signed endpoint: set
    | TELEMETRY_DIRECTIVES_SECRET there and give the same secret here, keyed
    | by its service.name:
    |
    |   'services' => [
    |       'shop' => ['url' => 'https://shop.example', 'secret' => env('SHOP_DIRECTIVES_SECRET'), 'environment' => 'production'],
    |   ],
    |
    | "environment" lets an environment-locked viewer see the target. Placing
    | or removing one needs the manageTelemetryUi ability; see
    | docs/core-concepts/monitors.md for the scope rule.
    |
    */

    'monitors' => [
        'enabled' => (bool) env('TELEMETRY_UI_MONITORS', true),
        'local' => (bool) env('TELEMETRY_UI_MONITORS_LOCAL', true),
        'services' => [],
        'timeout' => (float) env('TELEMETRY_UI_MONITORS_TIMEOUT', 5.0),
    ],

    'hosts' => [
        'cpu' => env('TELEMETRY_UI_HOSTS_CPU'),
        'memory' => env('TELEMETRY_UI_HOSTS_MEMORY'),
        'host_label' => env('TELEMETRY_UI_HOSTS_HOST_LABEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP server
    |--------------------------------------------------------------------------
    |
    | The local stdio server (`php artisan mcp:start telemetry-ui`) always
    | works. To also expose it over HTTP for a hosted agent, flip "web.enabled":
    | laravel/mcp serves the endpoint and — with laravel/passport installed —
    | the OAuth 2.1 authorization server and the Dynamic Client Registration
    | endpoint, so clients can self-register. No custom OAuth code.
    |
    |   composer require laravel/passport      # then run its install
    |   TELEMETRY_UI_MCP_WEB=true
    |
    */

    'mcp' => [
        'web' => [
            'enabled' => (bool) env('TELEMETRY_UI_MCP_WEB', false),
            'path' => env('TELEMETRY_UI_MCP_PATH', 'telemetry-ui/mcp'),
            // auth:api is the ONLY auth on this endpoint (the dashboard gate does
            // not cover it) — keep it, and throttle it like the dashboard routes.
            'middleware' => ['auth:api', 'throttle:60,1'],
            'oauth' => (bool) env('TELEMETRY_UI_MCP_OAUTH', true),
        ],

        // The write tools (`monitor`) are off unless switched on: an agent
        // that can read your traces should not by default be able to turn
        // recording up. Over HTTP they also need the manageTelemetryUi ability.
        'write' => (bool) env('TELEMETRY_UI_MCP_WRITE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Signal context (correlation)
    |--------------------------------------------------------------------------
    |
    | The thing an app-only monitor can't do: correlate a trace with the host
    | and runtime signals recorded around it, because the same Prometheus
    | scrapes system and process metrics — and node_exporter, mysqld_exporter,
    | … when you run them — right next to the app.
    |
    | Each signal is a PromQL template with a `{scope}` token that expands to
    | the scope's matcher list (service_name, host_name). Signals resolve
    | independently and fail-open, so a signal whose metric is absent (e.g. no
    | node_exporter) is simply skipped — never an error. `window` is the number
    | of seconds padded around a trace so surrounding metric samples land in
    | view.
    |
    | Exporters that don't carry the app's labels take the bare values instead:
    | `{host}` (the trace's host), `{service}` and `{environment}`. A signal
    | that uses one the trace doesn't have (no host attribute) is skipped.
    |   ['label' => 'Load (1m)', 'group' => 'host', 'unit' => 'number',
    |    'query' => 'max(node_load1{nodename="{host}"})'],
    |   ['label' => 'DB threads running', 'group' => 'db', 'unit' => 'number',
    |    'query' => 'sum(mysql_global_status_threads_running{environment="{environment}"})'],
    |
    | A signal that is zero for the whole window is hidden, unless it sets
    | `'keep_zero' => true` — for the ones where zero is the finding (a worker
    | or listen queue that stayed empty clears the server).
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Infrastructure discovery
    |--------------------------------------------------------------------------
    |
    | Adding an exporter is an infrastructure job: install node_exporter on
    | the box, point redis_exporter at the cache, scrape them. Nothing needs
    | configuring here — `telemetry-ui:discover` finds what is there and ties
    | each instance to the host or dependency your traces already name.
    |
    | Run it on a schedule; infrastructure changes at deploy speed, not
    | request speed. The map is cached for "ttl" seconds.
    |
    */

    'discovery' => [
        'ttl' => (int) env('TELEMETRY_UI_DISCOVERY_TTL', 900),
        // How far back to look for the host and dependency names to match
        // exporters against. Some backends return nothing for a tag-values
        // lookup with no explicit range, so this is never left open.
        'lookback' => (int) env('TELEMETRY_UI_DISCOVERY_LOOKBACK', 86_400),
    ],

    'context' => [
        'enabled' => (bool) env('TELEMETRY_UI_CONTEXT', true),
        'window' => (int) env('TELEMETRY_UI_CONTEXT_WINDOW', 600),
        // Lookback for each signal's "typical" baseline, so a tile can say
        // "95% (typical 30%)" and flag what was actually different.
        'baseline_window' => (int) env('TELEMETRY_UI_CONTEXT_BASELINE', 21_600),
        // Baselines are multi-hour averages that barely move, so they're cached
        // this long (well beyond the live query cache) and shared across nearby
        // traces — keeps opening a trace cheap.
        'baseline_ttl' => (int) env('TELEMETRY_UI_CONTEXT_BASELINE_TTL', 120),
        // Each entry is PromQL with a {scope} token. A signal whose metric
        // does not exist is skipped silently — which is the right behaviour
        // for an optional exporter, and the reason a typo in one of these
        // is invisible. `telemetry-ui:check --signals` reports which ones
        // actually resolve; run it after editing this list.
        //
        // The OpenTelemetry convention appends `_ratio` to a gauge whose
        // unit is `1`, but not every pipeline applies it, so the shipped
        // signals accept either spelling.
        'signals' => [
            ['label' => 'Host CPU', 'group' => 'host', 'unit' => 'ratio', 'query' => 'avg(system_cpu_utilization_ratio{{scope}} or system_cpu_utilization{{scope}})'],
            ['label' => 'Load avg', 'group' => 'host', 'unit' => 'number', 'query' => 'max(system_cpu_load_average_1m{{scope}})'],
            ['label' => 'Host memory', 'group' => 'host', 'unit' => 'ratio', 'query' => 'avg(system_memory_utilization_ratio{{scope},system_memory_state="used"} or system_memory_utilization{{scope},system_memory_state="used"})'],
            ['label' => 'Net in', 'group' => 'host', 'unit' => 'bytes/s', 'query' => 'sum(rate(system_network_io_bytes_total{{scope},network_io_direction="receive"}[1m]))'],
            ['label' => 'Disk used', 'group' => 'host', 'unit' => 'bytes', 'query' => 'max(system_filesystem_usage_bytes{{scope},system_filesystem_state="used"})'],
            ['label' => 'Process RSS', 'group' => 'runtime', 'unit' => 'bytes', 'query' => 'avg(process_memory_rss_bytes{{scope}})'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Host services
    |--------------------------------------------------------------------------
    |
    | The services running ON a host, from their own Prometheus exporters,
    | shown on the host-detail page. `{host}` expands to the escaped host
    | name — exporters label instances differently (host:port, nodename, …),
    | so the matcher lives in the query. A service whose `up` probe returns
    | nothing simply doesn't render, so listing exporters you don't run is
    | free. Add your own (queues, search, anything with an exporter).
    |
    */

    'host-services' => [
        'mysql' => [
            'label' => 'MySQL',
            'up' => 'mysql_up{instance=~"{host}(:.*)?"}',
            'tiles' => [
                ['label' => 'Connections', 'query' => 'sum(mysql_global_status_threads_connected{instance=~"{host}(:.*)?"})'],
                ['label' => 'Queries/s', 'query' => 'sum(rate(mysql_global_status_queries{instance=~"{host}(:.*)?"}[5m]))', 'unit' => 'raw'],
                ['label' => 'Slow queries', 'query' => 'sum(increase(mysql_global_status_slow_queries{instance=~"{host}(:.*)?"}[1h]))'],
                ['label' => 'Uptime', 'query' => 'max(mysql_global_status_uptime{instance=~"{host}(:.*)?"}) / 86400', 'unit' => 'raw'],
            ],
        ],
        'redis' => [
            'label' => 'Redis',
            'up' => 'redis_up{instance=~"{host}(:.*)?"}',
            'tiles' => [
                ['label' => 'Memory', 'query' => 'sum(redis_memory_used_bytes{instance=~"{host}(:.*)?"})', 'unit' => 'bytes'],
                ['label' => 'Clients', 'query' => 'sum(redis_connected_clients{instance=~"{host}(:.*)?"})'],
                ['label' => 'Ops/s', 'query' => 'sum(rate(redis_commands_processed_total{instance=~"{host}(:.*)?"}[5m]))', 'unit' => 'raw'],
                ['label' => 'Hit ratio', 'query' => 'sum(rate(redis_keyspace_hits_total{instance=~"{host}(:.*)?"}[5m])) / (sum(rate(redis_keyspace_hits_total{instance=~"{host}(:.*)?"}[5m])) + sum(rate(redis_keyspace_misses_total{instance=~"{host}(:.*)?"}[5m])))', 'unit' => 'ratio'],
            ],
        ],
        'postgres' => [
            'label' => 'PostgreSQL',
            'up' => 'pg_up{instance=~"{host}(:.*)?"}',
            'tiles' => [
                ['label' => 'Connections', 'query' => 'sum(pg_stat_activity_count{instance=~"{host}(:.*)?"})'],
                ['label' => 'TPS', 'query' => 'sum(rate(pg_stat_database_xact_commit{instance=~"{host}(:.*)?"}[5m]))', 'unit' => 'raw'],
            ],
        ],
        'node' => [
            'label' => 'node_exporter',
            'up' => 'node_exporter_build_info{instance=~"{host}(:.*)?"}',
            'tiles' => [
                ['label' => 'Uptime (days)', 'query' => '(max(node_time_seconds{instance=~"{host}(:.*)?"}) - max(node_boot_time_seconds{instance=~"{host}(:.*)?"})) / 86400', 'unit' => 'raw'],
                ['label' => 'Disk free', 'query' => 'sum(node_filesystem_avail_bytes{instance=~"{host}(:.*)?",mountpoint="/"})', 'unit' => 'bytes'],
            ],
        ],
        // No exporter needed: what the app ITSELF measured about the
        // database it queries from this host (laravel-telemetry's
        // db.queries counter, alpha.17+). 'observed' kind: proves usage,
        // not health.
        'database-app' => [
            'label' => 'Database (seen by app)',
            'kind' => 'observed',
            'up' => 'sum(rate(db_queries_total{host_name="{host}"}[10m]))',
            'note' => 'App-side view only. Point mysqld_exporter / postgres_exporter at this Prometheus for real health, connections and slow-query stats.',
            'tiles' => [
                ['label' => 'Queries/s', 'query' => 'sum(rate(db_queries_total{host_name="{host}"}[5m]))', 'unit' => 'raw'],
                ['label' => 'Queries (1h)', 'query' => 'sum(increase(db_queries_total{host_name="{host}"}[1h]))'],
                // N+1s are their own counter from laravel-telemetry 3.5; before
                // that the duplicate counter held them.
                ['label' => 'N+1 detections (1h)', 'query' => 'sum(increase(db_queries_n_plus_one_total{host_name="{host}"}[1h]))'],
                ['label' => 'Duplicate queries (1h)', 'query' => 'sum(increase(db_queries_duplicated_total{host_name="{host}"}[1h]))'],
            ],
        ],
        // No exporter needed: what the app ITSELF measured about the Redis
        // it talks to from this host (laravel-telemetry's redis.commands,
        // TELEMETRY_INSTRUMENT_REDIS=true). 'observed' kind: this can never
        // claim up/down — it only proves the app used Redis recently.
        'redis-app' => [
            'label' => 'Redis (seen by app)',
            'kind' => 'observed',
            'up' => 'sum(rate(redis_commands_total{host_name="{host}"}[10m]))',
            'note' => 'App-side view only. Point redis_exporter at this Prometheus for real health, memory, clients and hit ratio.',
            'tiles' => [
                ['label' => 'Commands/s', 'query' => 'sum(rate(redis_commands_total{host_name="{host}"}[5m]))', 'unit' => 'raw'],
                ['label' => 'Commands (1h)', 'query' => 'sum(increase(redis_commands_total{host_name="{host}"}[1h]))'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Annotations
    |--------------------------------------------------------------------------
    |
    | Point-in-time markers drawn as vertical lines on every chart, the way
    | Grafana annotations map regressions to deploys. cboxdk/laravel-telemetry
    | emits `app.deployment` events (via `php artisan telemetry:deploy`) into
    | the logs backend; each marker below is matched there by its event name,
    | reading id/notes from the event's structured metadata.
    |
    */

    'annotations' => [
        'enabled' => (bool) env('TELEMETRY_UI_ANNOTATIONS', true),
        'ttl' => (int) env('TELEMETRY_UI_ANNOTATIONS_TTL', 30),

        // Each marker both reads (matched in Loki by "event") and writes (via
        // `php artisan telemetry-ui:annotate <key>`, which emits the event
        // through the telemetry pipeline). Add your own — anything worth a
        // vertical line on every chart.
        'markers' => [
            'deploy' => [
                'event' => 'app.deployment',
                'label' => 'Deploy',
                'color' => '#c084fc',
                'id_label' => 'deployment_id',
                'notes_label' => 'deployment_notes',
            ],
            'incident' => [
                'event' => 'app.incident',
                'label' => 'Incident',
                'color' => '#f87171',
                'id_label' => 'incident_id',
                'notes_label' => 'incident_notes',
            ],
            'scaling' => [
                'event' => 'app.scaling',
                'label' => 'Scaling',
                'color' => '#60a5fa',
                'id_label' => 'scaling_id',
                'notes_label' => 'scaling_notes',
            ],
            'migration' => [
                'event' => 'app.migration',
                'label' => 'Migration',
                'color' => '#34d399',
                'id_label' => 'migration_id',
                'notes_label' => 'migration_notes',
            ],
            'feature' => [
                'event' => 'app.feature_flag',
                'label' => 'Feature flag',
                'color' => '#fbbf24',
                'id_label' => 'feature_id',
                'notes_label' => 'feature_notes',
            ],
            'version' => [
                'event' => 'app.version',
                'label' => 'Version',
                'color' => '#2dd4bf',
                'id_label' => 'version_id',
                'notes_label' => 'version_notes',
            ],
            // Cache purges. `cache_purge` is the app-agnostic marker — emit it
            // from your own purge hook (`php artisan telemetry-ui:annotate
            // cache_purge --id=redis --notes="full flush"`).
            'cache_purge' => [
                'event' => 'app.cache_purge',
                'label' => 'Cache purge',
                'color' => '#fb923c',
                'id_label' => 'cache_type',
                'notes_label' => 'cache_notes',
            ],
            // cboxdk/statamic-telemetry emits statamic.cache.purge on every
            // stache/static/glide clear (cache.type + cache.trigger attrs),
            // so Statamic purges land on charts with no wiring.
            'statamic_cache_purge' => [
                'event' => 'statamic.cache.purge',
                'label' => 'Statamic cache purge',
                'color' => '#f472b6',
                'id_label' => 'cache_type',
                'notes_label' => 'cache_trigger',
            ],
        ],

        // Proactive: `telemetry-ui:scan-versions` (schedule it every few
        // minutes) detects a newly-seen laravel_version in the metrics and
        // auto-emits a "version" annotation for it — so an un-announced deploy
        // still lands on the charts. Stateless: it dedups against the version
        // annotations already in Loki, no local store.
        'auto_version' => [
            'enabled' => (bool) env('TELEMETRY_UI_AUTO_VERSION', false),
            'metric' => env('TELEMETRY_UI_AUTO_VERSION_METRIC', 'system_cpu_utilization_ratio'),
            'marker' => 'version',
            'lookback_days' => (int) env('TELEMETRY_UI_AUTO_VERSION_LOOKBACK', 30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard panels
    |--------------------------------------------------------------------------
    |
    | The panels on the dashboard page, in order. Packages may append their
    | own at runtime with TelemetryUi::panel(MyPanel::class); entries listed
    | here come first. A panel is a plain class extending
    | Cbox\TelemetryUi\Panels\Panel — see docs/extension-points.
    |
    */

    'panels' => [
        RequestsActivity::class,
        RequestDuration::class,
        ExceptionsOverview::class,
        JobsOverview::class,
        RoutesNeedingAttention::class,
        DeploysTimeline::class,
    ],

];
