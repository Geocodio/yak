<?php

/*
|--------------------------------------------------------------------------
| Budget Defaults
|--------------------------------------------------------------------------
*/

test('daily_budget_usd defaults to 50', function () {
    expect(config('yak.daily_budget_usd'))->toBe(50.0);
});

test('max_budget_per_task defaults to 5', function () {
    expect(config('yak.max_budget_per_task'))->toBe(5.0);
});

/*
|--------------------------------------------------------------------------
| Task Execution Defaults
|--------------------------------------------------------------------------
*/

test('max_attempts defaults to 2', function () {
    expect(config('yak.max_attempts'))->toBe(2);
});

test('max_turns defaults to 300', function () {
    expect(config('yak.max_turns'))->toBe(300);
});

test('default_model defaults to opus', function () {
    expect(config('yak.default_model'))->toBe('opus');
});

test('clarification_ttl_days defaults to 3', function () {
    expect(config('yak.clarification_ttl_days'))->toBe(3);
});

test('large_change_threshold defaults to 200', function () {
    expect(config('yak.large_change_threshold'))->toBe(200);
});

/*
|--------------------------------------------------------------------------
| MCP & API Keys
|--------------------------------------------------------------------------
*/

test('mcp_config_path defaults to null', function () {
    expect(config('yak.mcp_config_path'))->toBeNull();
});

test('anthropic_api_key reads from env', function () {
    expect(config('yak.anthropic_api_key'))->toBeNull();
});

/*
|--------------------------------------------------------------------------
| CI Scan Defaults
|--------------------------------------------------------------------------
*/

test('ci scan_interval_minutes defaults to 120', function () {
    expect(config('yak.ci_scan.scan_interval_minutes'))->toBe(120);
});

test('ci max_failure_age_hours defaults to 48', function () {
    expect(config('yak.ci_scan.max_failure_age_hours'))->toBe(48);
});

/*
|--------------------------------------------------------------------------
| Channel Configuration Structure
|--------------------------------------------------------------------------
*/

test('slack channel has required credential fields', function () {
    $slack = config('yak.channels.slack');

    expect($slack)->toHaveKeys(['driver', 'bot_token', 'signing_secret'])
        ->and($slack['driver'])->toBe('slack');
});

test('linear channel has required credential fields', function () {
    $linear = config('yak.channels.linear');

    expect($linear)->toHaveKeys([
        'driver', 'webhook_secret',
        'oauth_client_id', 'oauth_client_secret', 'oauth_redirect_uri', 'oauth_scopes',
        'done_state_id', 'cancelled_state_id', 'in_review_state_id',
    ])
        ->and($linear['driver'])->toBe('linear');
});

test('sentry channel has required credential fields', function () {
    $sentry = config('yak.channels.sentry');

    expect($sentry)->toHaveKeys([
        'driver', 'auth_token', 'webhook_secret', 'org_slug',
        'region_url', 'min_events', 'min_actionability',
    ])
        ->and($sentry['driver'])->toBe('sentry')
        ->and($sentry['region_url'])->toBe('https://us.sentry.io')
        ->and($sentry['min_events'])->toBe(5)
        ->and($sentry['min_actionability'])->toBe('medium');
});

test('drone channel has required credential fields', function () {
    $drone = config('yak.channels.drone');

    expect($drone)->toHaveKeys(['driver', 'url', 'token'])
        ->and($drone['driver'])->toBe('drone');
});

test('github channel has required credential fields', function () {
    $github = config('yak.channels.github');

    expect($github)->toHaveKeys(['driver', 'app_id', 'private_key', 'webhook_secret'])
        ->and($github['driver'])->toBe('github');
});

/*
|--------------------------------------------------------------------------
| PR Review Configuration
|--------------------------------------------------------------------------
*/

it('has pr_review config defaults', function () {
    expect(config('yak.pr_review.reaction_poll_window_days'))->toBe(30)
        ->and(config('yak.pr_review.max_findings_per_review'))->toBe(20)
        ->and(config('yak.pr_review.enabled_globally'))->toBeTrue()
        ->and(config('yak.pr_review.default_path_excludes'))->toBeArray()
        ->and(config('yak.pr_review.default_path_excludes'))->toContain('vendor/**');
});

it('has github app_bot_login config key', function () {
    expect(config('yak.channels.github.app_bot_login'))->not->toBeNull();
});
