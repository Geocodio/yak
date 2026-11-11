<?php

use App\DataTransferObjects\YakToolCall;
use App\DataTransferObjects\YakToolResult;
use App\Enums\ThreadSandboxState;
use App\Models\Repository;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\RefreshToolRouter;
use App\Services\Threads\RefreshTriggers;
use App\Services\Threads\YakToolRouter;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;

function refreshRouterThread(): Thread
{
    return Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running, 'repo' => 'acme/app']);
}

/** @param list<ProcessResult> $commandResults */
function queueRouterRefresh(string $yaml, string $files, array $commandResults = []): void
{
    $sandbox = app(SandboxManager::class);
    $sandbox->queueExecResult(Process::result($yaml));
    $sandbox->queueExecResult(Process::result($files));
    foreach ($commandResults as $result) {
        $sandbox->queueExecResult($result);
    }
}

function refreshRouter(?YakToolRouter $inner = null): RefreshToolRouter
{
    return new RefreshToolRouter($inner ?? Mockery::mock(YakToolRouter::class), app(RefreshTriggers::class));
}

function refreshCall(): YakToolCall
{
    return new YakToolCall('c1', 'request_refresh', ['reason' => 'lockfile changed']);
}

it('request_refresh runs the matching refresh commands and returns redacted output and exit codes', function () {
    $thread = refreshRouterThread();
    queueRouterRefresh("refresh:\n  composer.lock: composer install\n", "composer.lock\n", [Process::result('installed')]);

    $result = refreshRouter()->handle($thread, refreshCall());

    expect($result->isError)->toBeFalse()
        ->and($result->content)->toContain('$ composer install')->toContain('exit 0')->toContain('installed');
});

it('a failing command returns isError true and stops the chain', function () {
    $thread = refreshRouterThread();
    queueRouterRefresh("refresh:\n  composer.lock: composer install\n  package-lock.json: npm ci\n", "composer.lock\npackage-lock.json\n", [Process::result('boom', 'bad', 2)]);

    $result = refreshRouter()->handle($thread, refreshCall());

    expect($result->isError)->toBeTrue()
        ->and($result->content)->toContain('exit 2')->not->toContain('npm ci');
});

it('build credential values never appear in the result text', function () {
    Repository::factory()->create(['slug' => 'acme/app', 'build_credentials' => ['NODE_AUTH_TOKEN' => 'tok_secret']]);
    $thread = refreshRouterThread();
    queueRouterRefresh("refresh:\n  composer.lock: composer install\n", "composer.lock\n", [Process::result('token tok_secret, fetching https://user:pw@example.com/x')]);

    $result = refreshRouter()->handle($thread, refreshCall());

    expect($result->content)->not->toContain('tok_secret')->not->toContain('user:pw')
        ->toContain('[redacted]')->toContain('://[redacted]@example.com');
});

it('with nothing to refresh it says so', function () {
    $thread = refreshRouterThread();
    queueRouterRefresh("refresh:\n  composer.lock: composer install\n", "README.md\n");

    $result = refreshRouter()->handle($thread, refreshCall());

    expect($result->isError)->toBeFalse()->and($result->content)->toContain('Nothing to refresh');
});

it('a tool other than request_refresh passes to the inner router', function () {
    $thread = refreshRouterThread();
    $call = new YakToolCall('c2', 'other_tool', []);
    $inner = Mockery::mock(YakToolRouter::class);
    $inner->shouldReceive('handle')->once()->with($thread, $call)->andReturn(new YakToolResult(false, 'inner'));

    expect(refreshRouter($inner)->handle($thread, $call)->content)->toBe('inner');
});

it('the credentials reach the exec environment and not the agent', function () {
    Repository::factory()->create(['slug' => 'acme/app', 'build_credentials' => ['NODE_AUTH_TOKEN' => 'tok_secret']]);
    $thread = refreshRouterThread();
    queueRouterRefresh("refresh:\n  composer.lock: composer install\n", "composer.lock\n");

    $result = refreshRouter()->handle($thread, refreshCall());

    $envs = array_column(array_column(array_filter(app(SandboxManager::class)->calls, fn (array $call): bool => $call['operation'] === 'exec'), 'arguments'), 'env');
    expect($envs)->toContain(['NODE_AUTH_TOKEN' => 'tok_secret'])
        ->and($result->content)->not->toContain('tok_secret');
});

it('reports a failure to run without leaking the exception', function () {
    $thread = refreshRouterThread();
    $refresh = Mockery::mock(RefreshTriggers::class);
    $refresh->shouldReceive('run')->andThrow(new RuntimeException('tok_secret exploded'));

    $result = (new RefreshToolRouter(Mockery::mock(YakToolRouter::class), $refresh))->handle($thread, refreshCall());

    expect($result)->toEqual(new YakToolResult(true, 'The refresh could not run.'));
});
