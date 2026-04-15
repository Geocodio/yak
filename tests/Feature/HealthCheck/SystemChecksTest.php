<?php

use App\Models\YakTask;
use App\Services\HealthCheck\ClaudeAuthCheck;
use App\Services\HealthCheck\ClaudeCliCheck;
use App\Services\HealthCheck\HealthStatus;
use App\Services\HealthCheck\LastTaskCompletedCheck;
use App\Services\HealthCheck\RepositoriesCheck;
use App\Services\HealthCheck\WebhookSignaturesCheck;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

it('last-task-completed returns Ok with no tasks yet', function () {
    $result = (new LastTaskCompletedCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toBe('No completed tasks yet');
});

it('last-task-completed includes external id when available', function () {
    YakTask::factory()->success()->create(['external_id' => 'GEO-9999']);

    $result = (new LastTaskCompletedCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toContain('GEO-9999');
});

it('repositories check returns Ok when no active repositories', function () {
    $result = (new RepositoriesCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toBe('No active repositories');
});

it('claude cli check succeeds with version output', function () {
    Process::fake([
        'claude --version' => Process::result(output: 'claude v1.0.0'),
    ]);

    $result = (new ClaudeCliCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toContain('claude v1.0.0');
});

it('claude cli check fails when not installed', function () {
    Process::fake([
        'claude --version' => Process::result(exitCode: 127),
    ]);

    $result = (new ClaudeCliCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error);
});

it('claude auth check fails when session file missing', function () {
    config()->set('yak.sandbox.claude_config_source', '/tmp/nonexistent-yak-claude-' . uniqid());

    $result = (new ClaudeAuthCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error);
    expect($result->detail)->toContain('Session token missing');
});

it('webhook signatures check passes when no failures', function () {
    $result = (new WebhookSignaturesCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toBe('No rejected webhooks');
});

it('webhook signatures check reports rejected webhooks', function () {
    Cache::put('webhook-signature-failures:LinearWebhookController', 3);
    Cache::put('webhook-signature-failures:SlackWebhookController', 1);

    $result = (new WebhookSignaturesCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error);
    expect($result->detail)->toContain('Linear (3)');
    expect($result->detail)->toContain('Slack (1)');
});
