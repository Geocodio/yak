<?php

use App\Channels\Drone\DroneChannel;
use App\Channels\GitHub\GitHubChannel;
use App\Channels\Linear\LinearChannel;
use App\Channels\Sentry\SentryChannel;
use App\Channels\Slack\SlackChannel;

return [

    /*
    |--------------------------------------------------------------------------
    | Budget Configuration
    |--------------------------------------------------------------------------
    */

    'daily_budget_usd' => (float) env('YAK_DAILY_BUDGET_USD', 50),

    'max_budget_per_task' => (float) env('YAK_MAX_BUDGET_PER_TASK', 5),

    /*
    |--------------------------------------------------------------------------
    | Task Execution
    |--------------------------------------------------------------------------
    */

    'max_attempts' => (int) env('YAK_MAX_ATTEMPTS', 2),

    'max_turns' => (int) env('YAK_MAX_TURNS', 300),

    'default_model' => env('YAK_DEFAULT_MODEL', 'opus'),

    'clarification_ttl_days' => (int) env('YAK_CLARIFICATION_TTL_DAYS', 3),

    // Emit an extra "starting work" progress notification when the
    // agent picks up a task. Closes the silent gap between ack and
    // first push. Linear's Agent Activity UI is designed for progress;
    // Slack gets it as an extra in-thread reply. Default on.
    'emit_start_progress' => (bool) env('YAK_EMIT_START_PROGRESS', true),

    // Intent classifier: when a task comes in without an explicit
    // `research:` prefix, a cheap Haiku call decides Fix vs Research.
    // Disable for tests that don't want to mock the AI SDK.
    'intent_classifier' => [
        'enabled' => (bool) env('YAK_INTENT_CLASSIFIER_ENABLED', true),
    ],

    'large_change_threshold' => (int) env('YAK_LARGE_CHANGE_THRESHOLD', 200),

    'git_user_name' => env('YAK_GIT_USER_NAME', 'Yak'),

    'git_user_email' => env('YAK_GIT_USER_EMAIL', 'yak@noreply.github.com'),

    // Env vars to forward from the container to the sandboxed agent process.
    // Comma-separated list of var names (e.g. "NODE_AUTH_TOKEN,NPM_TOKEN").
    'agent_passthrough_env' => env('YAK_AGENT_PASSTHROUGH_ENV', ''),

    /*
    |--------------------------------------------------------------------------
    | Sandbox Configuration (Incus)
    |--------------------------------------------------------------------------
    |
    | Sandboxed task execution uses Incus system containers with ZFS
    | copy-on-write snapshots. Each task gets its own isolated container
    | with its own Docker daemon, network namespace, and filesystem.
    |
    */

    'sandbox' => [
        'enabled' => (bool) env('YAK_SANDBOX_ENABLED', true),
        'base_template' => env('YAK_SANDBOX_BASE_TEMPLATE', 'yak-base'),
        'snapshot_name' => env('YAK_SANDBOX_SNAPSHOT_NAME', 'ready'),

        // Bump this integer whenever `ansible/roles/incus/tasks/main.yml`
        // changes the yak-base image in a way repo templates must inherit
        // (new system packages, browser engine, language runtimes, etc.).
        // The matching `yak_sandbox_base_version` var in Ansible must be
        // bumped in the same commit. On the next task clone, any repo
        // template whose stored `sandbox_base_version` differs gets
        // destroyed and re-provisioned from the fresh yak-base.
        'base_version' => (int) env('YAK_SANDBOX_BASE_VERSION', 3),

        'cpu_limit' => (int) env('YAK_SANDBOX_CPU_LIMIT', 4),
        'memory_limit' => env('YAK_SANDBOX_MEMORY_LIMIT', '8GB'),
        'disk_limit' => env('YAK_SANDBOX_DISK_LIMIT', '30GB'),
        'workspace_path' => env('YAK_SANDBOX_WORKSPACE_PATH', '/workspace'),
        'results_path' => env('YAK_SANDBOX_RESULTS_PATH', '/results'),
        'claude_config_source' => env('YAK_SANDBOX_CLAUDE_CONFIG', '/home/yak/.claude'),
        'docker_config_source' => env('YAK_SANDBOX_DOCKER_CONFIG', '/home/yak/.docker/config.json'),
        'network' => env('YAK_SANDBOX_NETWORK', 'yak-sandbox'),
        'cleanup_after_hours' => (int) env('YAK_SANDBOX_CLEANUP_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | MCP Configuration
    |--------------------------------------------------------------------------
    */

    'mcp_config_path' => env('YAK_MCP_CONFIG_PATH'),

    /*
    |--------------------------------------------------------------------------
    | Claude Code plugin / skill paths
    |--------------------------------------------------------------------------
    |
    | Paths inside the Yak container where Claude Code stores user-scoped
    | plugin state and Ansible-provisioned skills. Both sit under
    | CLAUDE_CONFIG_DIR and follow Claude Code's conventions.
    */

    'plugins_dir' => env('YAK_PLUGINS_DIR', env('CLAUDE_CONFIG_DIR', '/home/yak/.claude') . '/plugins'),

    'skills_dir' => env('YAK_SKILLS_DIR', env('CLAUDE_CONFIG_DIR', '/home/yak/.claude') . '/skills'),

    /*
    |--------------------------------------------------------------------------
    | API Keys
    |--------------------------------------------------------------------------
    */

    'anthropic_api_key' => env('ANTHROPIC_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | CI Scan Configuration
    |--------------------------------------------------------------------------
    */

    'ci_timeout_minutes' => (int) env('YAK_CI_TIMEOUT_MINUTES', 30),

    'ci_scan' => [
        'scan_interval_minutes' => (int) env('YAK_SCAN_INTERVAL_MINUTES', 120),
        'max_failure_age_hours' => (int) env('YAK_MAX_FAILURE_AGE_HOURS', 48),
    ],

    /*
    |--------------------------------------------------------------------------
    | Channel Configuration
    |--------------------------------------------------------------------------
    */

    'channels' => [

        'slack' => [
            'driver' => 'slack',
            'bot_token' => env('YAK_SLACK_BOT_TOKEN'),
            'signing_secret' => env('YAK_SLACK_SIGNING_SECRET'),

            // Workspace URL (e.g. "https://acme.slack.com"). Used to build
            // deep links from the dashboard back to the original thread.
            'workspace_url' => env('YAK_SLACK_WORKSPACE_URL'),
        ],

        'linear' => [
            'driver' => 'linear',
            'webhook_secret' => env('YAK_LINEAR_WEBHOOK_SECRET'),
            'done_state_id' => env('YAK_LINEAR_DONE_STATE_ID'),
            'cancelled_state_id' => env('YAK_LINEAR_CANCELLED_STATE_ID'),
            'in_review_state_id' => env('YAK_LINEAR_IN_REVIEW_STATE_ID'),

            // OAuth2 app credentials — used by the outbound driver to post
            // comments and update issue state as the Yak app.
            'oauth_client_id' => env('YAK_LINEAR_OAUTH_CLIENT_ID'),
            'oauth_client_secret' => env('YAK_LINEAR_OAUTH_CLIENT_SECRET'),
            'oauth_redirect_uri' => env('YAK_LINEAR_OAUTH_REDIRECT_URI'),
            'oauth_scopes' => env('YAK_LINEAR_OAUTH_SCOPES', 'read,write,app:assignable,app:mentionable'),
        ],

        'sentry' => [
            'driver' => 'sentry',
            'auth_token' => env('YAK_SENTRY_AUTH_TOKEN'),
            'webhook_secret' => env('YAK_SENTRY_WEBHOOK_SECRET'),
            'org_slug' => env('YAK_SENTRY_ORG_SLUG'),
            'region_url' => env('YAK_SENTRY_REGION_URL', 'https://us.sentry.io'),
            'min_events' => (int) env('YAK_SENTRY_MIN_EVENTS', 5),
            'min_actionability' => env('YAK_SENTRY_MIN_ACTIONABILITY', 'medium'),
        ],

        'drone' => [
            'driver' => 'drone',
            'url' => env('YAK_DRONE_URL'),
            'token' => env('YAK_DRONE_TOKEN'),
        ],

        'github' => [
            'driver' => 'github',
            'app_id' => env('YAK_GITHUB_APP_ID'),
            'private_key' => env('YAK_GITHUB_PRIVATE_KEY_PATH') && file_exists((string) env('YAK_GITHUB_PRIVATE_KEY_PATH'))
                ? file_get_contents((string) env('YAK_GITHUB_PRIVATE_KEY_PATH'))
                : env('YAK_GITHUB_PRIVATE_KEY', ''),
            'installation_id' => (int) env('YAK_GITHUB_INSTALLATION_ID'),
            'webhook_secret' => env('YAK_GITHUB_WEBHOOK_SECRET'),
            'app_bot_login' => env('GITHUB_APP_BOT_LOGIN', 'yak-bot[bot]'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Channel Classes
    |--------------------------------------------------------------------------
    |
    | Fully-qualified class names of channel entry classes. Each must
    | implement App\Channels\Channel. Populated incrementally as each
    | channel is migrated into app/Channels/{Name}/. Until a channel
    | appears here, ChannelServiceProvider still uses the legacy
    | hardcoded controller map for that channel's webhook routes.
    |
    */

    'channel_classes' => [
        GitHubChannel::class,   // always-on
        DroneChannel::class,
        SentryChannel::class,
        LinearChannel::class,
        SlackChannel::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Branch Deployments Configuration
    |--------------------------------------------------------------------------
    */

    'deployments' => [
        'hostname_suffix' => env('YAK_DEPLOYMENTS_HOSTNAME_SUFFIX', 'yak.example.com'),
        'running_cap' => (int) env('YAK_DEPLOYMENTS_RUNNING_CAP', 6),
        'idle_minutes' => (int) env('YAK_DEPLOYMENTS_IDLE_MINUTES', 15),
        'destroy_days' => (int) env('YAK_DEPLOYMENTS_DESTROY_DAYS', 30),
        'eviction_grace_minutes' => (int) env('YAK_DEPLOYMENTS_EVICTION_GRACE_MINUTES', 5),
        'default_port' => (int) env('YAK_DEPLOYMENTS_DEFAULT_PORT', 80),
        'default_health_probe_path' => env('YAK_DEPLOYMENTS_DEFAULT_HEALTH_PROBE_PATH', '/'),
        'default_wake_timeout_seconds' => (int) env('YAK_DEPLOYMENTS_DEFAULT_WAKE_TIMEOUT_SECONDS', 120),
        'default_cold_start_timeout_seconds' => (int) env('YAK_DEPLOYMENTS_DEFAULT_COLD_START_TIMEOUT_SECONDS', 60),
        'default_checkout_refresh_timeout_seconds' => (int) env('YAK_DEPLOYMENTS_DEFAULT_CHECKOUT_REFRESH_TIMEOUT_SECONDS', 60),
        'default_health_probe_timeout_seconds' => (int) env('YAK_DEPLOYMENTS_DEFAULT_HEALTH_PROBE_TIMEOUT_SECONDS', 30),

        'wake_request_budget_seconds' => (int) env('YAK_DEPLOYMENTS_WAKE_REQUEST_BUDGET_SECONDS', 3),

        'internal' => [
            // CIDR range of the reverse-proxy (ingress) source. Yak's default install
            // runs Caddy on the host; requests to /internal/deployments/* arrive via
            // the Docker bridge gateway (172.18.0.1). The default covers that case.
            'ingress_ip_cidr' => env('YAK_DEPLOYMENTS_INGRESS_IP_CIDR', '172.18.0.0/16'),
        ],

        'share' => [
            'default_days' => (int) env('YAK_DEPLOYMENTS_SHARE_DEFAULT_DAYS', 7),
            'max_days' => (int) env('YAK_DEPLOYMENTS_SHARE_MAX_DAYS', 30),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | PR Review Configuration
    |--------------------------------------------------------------------------
    */

    'pr_review' => [
        'reaction_poll_window_days' => (int) env('YAK_PR_REVIEW_POLL_DAYS', 30),
        'max_findings_per_review' => (int) env('YAK_PR_REVIEW_MAX_FINDINGS', 20),
        'enabled_globally' => (bool) env('YAK_PR_REVIEW_ENABLED_GLOBALLY', true),
        'trigger_label' => env('YAK_PR_REVIEW_TRIGGER_LABEL', 'yak-review'),
        'default_path_excludes' => [
            'vendor/**', 'node_modules/**', 'public/build/**', 'public/hot',
            'storage/**', '*.min.js', '*.min.css',
            '.idea/**', '.vscode/**',
        ],
    ],

];
