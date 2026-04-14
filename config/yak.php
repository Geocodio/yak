<?php

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

    'agent_runner' => env('YAK_AGENT_RUNNER', 'claude_code'),

    'clarification_ttl_days' => (int) env('YAK_CLARIFICATION_TTL_DAYS', 3),

    'large_change_threshold' => (int) env('YAK_LARGE_CHANGE_THRESHOLD', 200),

    'git_user_name' => env('YAK_GIT_USER_NAME', 'Yak'),

    'git_user_email' => env('YAK_GIT_USER_EMAIL', 'yak@noreply.github.com'),

    // Env vars to forward from the container to the sandboxed agent process.
    // Comma-separated list of var names (e.g. "NODE_AUTH_TOKEN,NPM_TOKEN").
    'agent_passthrough_env' => env('YAK_AGENT_PASSTHROUGH_ENV', ''),

    /*
    |--------------------------------------------------------------------------
    | MCP Configuration
    |--------------------------------------------------------------------------
    */

    'mcp_config_path' => env('YAK_MCP_CONFIG_PATH'),

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
            'oauth_scopes' => env('YAK_LINEAR_OAUTH_SCOPES', 'read,write'),

            // Personal API key read by the Linear MCP server (Claude Code uses
            // this during agent runs — not the comment/state update path).
            'mcp_api_key' => env('YAK_LINEAR_MCP_API_KEY'),
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
        ],

    ],

];
