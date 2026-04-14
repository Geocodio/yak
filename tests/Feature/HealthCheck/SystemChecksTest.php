<?php

use App\Models\Repository;
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

it('repositories check returns Ok when all fetchable', function () {
    Process::fake([
        '*ls-remote*' => Process::result(output: 'abc123 HEAD'),
    ]);
    Repository::factory()->create(['is_active' => true, 'slug' => 'foo/bar']);

    $result = (new RepositoriesCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toBe('1/1 active repositories OK');
});

it('claude cli check succeeds with version output', function () {
    Process::fake([
        'claude --version' => Process::result(output: 'claude v1.0.0'),
    ]);

    $result = (new ClaudeCliCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toBe('Responding, claude v1.0.0');
});

it('claude cli check fails when not responding', function () {
    Process::fake([
        'claude --version' => Process::result(exitCode: 1),
    ]);

    $result = (new ClaudeCliCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error);
    expect($result->detail)->toBe('Not responding');
});

it('claude auth check succeeds when authenticated', function () {
    Process::fake([
        'claude auth status' => Process::result(output: 'Logged in'),
    ]);

    $result = (new ClaudeAuthCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toBe('Authenticated');
});

it('claude auth check fails when not authenticated', function () {
    Process::fake([
        'claude auth status' => Process::result(exitCode: 1),
    ]);

    $result = (new ClaudeAuthCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error);
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
