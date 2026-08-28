<?php

use App\Livewire\HealthRow;
use App\Models\User;
use App\Services\HealthCheck\ClaudeAuthCheck;
use App\Services\HealthCheck\HealthResult;
use App\Services\HealthCheck\HealthStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Process::fake([
        'pgrep *' => Process::result(output: '12345'),
        'claude *' => Process::result(output: 'claude v1.0.0'),
    ]);
});

it('renders a placeholder before lazy-loading', function () {
    Livewire::test(HealthRow::class, ['checkId' => 'queue-worker'])
        ->assertSee('Queue Worker')
        ->assertSee('animate-pulse', escape: false);
});

it('caches the result for 60 seconds', function () {
    $row = new HealthRow;
    $row->checkId = 'queue-worker';

    expect($row->result()->detail)->toBe('Running, PID 12345');

    Process::fake([
        'pgrep *' => Process::result(exitCode: 1),
    ]);

    expect($row->result()->detail)->toBe('Running, PID 12345');
});

it('refresh action clears the cache and re-runs the check', function () {
    $row = new HealthRow;
    $row->checkId = 'queue-worker';

    expect($row->result()->detail)->toBe('Running, PID 12345');

    Process::fake([
        'pgrep *' => Process::result(exitCode: 1),
    ]);

    $row->refresh();

    expect($row->result()->status)->toBe(HealthStatus::Error);
});

it('responds to the global refresh event', function () {
    Cache::put('health:check:queue-worker', 'stale');

    $row = new HealthRow;
    $row->checkId = 'queue-worker';
    $row->handleRefresh();

    expect(Cache::get('health:check:queue-worker'))->toBeNull();
});

it('renders the stored claude-auth result without probing inference', function () {
    Cache::put(ClaudeAuthCheck::LAST_RESULT_CACHE_KEY, [
        'result' => HealthResult::ok('Authenticated'),
        'checked_at' => now(),
    ], now()->addDay());

    $row = new HealthRow;
    $row->checkId = 'claude-auth';

    expect($row->result()->detail)->toBe('Authenticated');
    expect($row->result()->status)->toBe(HealthStatus::Ok);

    Process::assertNothingRan();
});

it('reports not yet probed for claude-auth when no scheduled result exists', function () {
    Cache::forget(ClaudeAuthCheck::LAST_RESULT_CACHE_KEY);

    $row = new HealthRow;
    $row->checkId = 'claude-auth';

    $result = $row->result();

    expect($result->status)->not->toBe(HealthStatus::Ok);
    expect($result->detail)->toContain('Not yet probed');

    Process::assertNothingRan();
});
