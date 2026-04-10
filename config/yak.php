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

    'max_turns' => (int) env('YAK_MAX_TURNS', 40),

    'default_model' => env('YAK_DEFAULT_MODEL', 'opus'),

    'clarification_ttl_days' => (int) env('YAK_CLARIFICATION_TTL_DAYS', 3),

    'large_change_threshold' => (int) env('YAK_LARGE_CHANGE_THRESHOLD', 200),

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
            'api_key' => env('YAK_LINEAR_API_KEY'),
            'webhook_secret' => env('YAK_LINEAR_WEBHOOK_SECRET'),
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
            'private_key' => env('YAK_GITHUB_PRIVATE_KEY'),
            'webhook_secret' => env('YAK_GITHUB_WEBHOOK_SECRET'),
        ],

    ],

];
