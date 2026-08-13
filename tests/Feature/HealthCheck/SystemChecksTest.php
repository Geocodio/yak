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

/**
 * Create a throwaway Claude config dir (with the sibling .claude.json
 * session file ClaudeAuthCheck requires) and return its path.
 */
function makeClaudeConfigDir(): string
{
    $base = sys_get_temp_dir() . '/yak-auth-' . uniqid();
    $configDir = $base . '/claude';
    mkdir($configDir, 0755, true);
    file_put_contents($base . '/.claude.json', '{}');

    return $configDir;
}

it('claude auth check fails when session file missing', function () {
    // Use a nested path so both the config dir and its parent (where the
    // .claude.json lookup happens) are guaranteed not to exist.
    config()->set('yak.sandbox.claude_config_source', '/tmp/nonexistent-yak-' . uniqid() . '/claude');

    $result = (new ClaudeAuthCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error);
    expect($result->detail)->toContain('Session token missing');
});

it('claude auth check gives the CLI enough time to complete a token refresh', function () {
    // With an expired access token, `claude auth status` performs an OAuth
    // refresh. The old 15s cap SIGKILLed the CLI mid-refresh, orphaning
    // .oauth_refresh.lock and blocking every subsequent refresh (task 5434).
    $configDir = makeClaudeConfigDir();
    config()->set('yak.sandbox.claude_config_source', $configDir);

    Process::fake(['*' => Process::result(output: '{"loggedIn":true}')]);

    (new ClaudeAuthCheck)->run();

    Process::assertRan(fn ($process) => str_contains($process->command, 'claude auth status')
        && $process->timeout === 60);
});

it('claude auth check sweeps a stale oauth refresh lock before running', function () {
    $configDir = makeClaudeConfigDir();
    $lockDir = $configDir . '/.oauth_refresh.lock';
    mkdir($lockDir);
    touch($lockDir, time() - 3600);

    config()->set('yak.sandbox.claude_config_source', $configDir);
    Process::fake(['*' => Process::result(output: '{"loggedIn":true}')]);

    $result = (new ClaudeAuthCheck)->run();

    expect(is_dir($lockDir))->toBeFalse();
    expect($result->status)->toBe(HealthStatus::Ok);
});

it('claude auth check leaves a fresh oauth refresh lock alone', function () {
    // A recent lock likely belongs to an in-flight refresh; deleting it
    // could corrupt a rotation that is about to be persisted.
    $configDir = makeClaudeConfigDir();
    $lockDir = $configDir . '/.oauth_refresh.lock';
    mkdir($lockDir);

    config()->set('yak.sandbox.claude_config_source', $configDir);
    Process::fake(['*' => Process::result(output: '{"loggedIn":true}')]);

    (new ClaudeAuthCheck)->run();

    expect(is_dir($lockDir))->toBeTrue();
});

it('webhook signatures check passes when no failures', function () {
    $result = (new WebhookSignaturesCheck)->run();

    expect($result->status)->toBe(HealthStatus::Ok);
    expect($result->detail)->toBe('No rejected webhooks');
});

it('webhook signatures check reports rejected webhooks', function () {
    Cache::put('webhook-signature-failures:linear', 3);
    Cache::put('webhook-signature-failures:slack', 1);

    $result = (new WebhookSignaturesCheck)->run();

    expect($result->status)->toBe(HealthStatus::Error);
    expect($result->detail)->toContain('Linear (3)');
    expect($result->detail)->toContain('Slack (1)');
});
