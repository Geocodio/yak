<?php

use App\Enums\ThreadSandboxState;
use App\Models\Repository;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\RefreshTriggers;
use Illuminate\Support\Facades\Process;

function refreshThread(array $attributes = []): Thread
{
    return Thread::factory()->create(['sandbox_state' => ThreadSandboxState::Running, 'repo' => 'acme/app', ...$attributes]);
}

/** Queues the exec results a run() consumes: config, file list, then one per command. */
function queueRefresh(string $yaml, string $files, array $commandResults = []): void
{
    $sandbox = app(SandboxManager::class);
    $sandbox->queueExecResult(Process::result($yaml));
    $sandbox->queueExecResult(Process::result($files));
    foreach ($commandResults as $result) {
        $sandbox->queueExecResult($result);
    }
}

function execCommands(): array
{
    return array_column(array_column(array_filter(app(SandboxManager::class)->calls, fn (array $call): bool => $call['operation'] === 'exec'), 'arguments'), 'command');
}

test('glob matching cases', function (string $glob, string $file, bool $matches) {
    expect(app(RefreshTriggers::class)->matching([$glob => 'run'], [$file]))->toBe($matches ? ['run'] : []);
})->with([
    ['composer.lock', 'composer.lock', true],
    ['composer.lock', 'sub/composer.lock', false],
    ['database/migrations/**', 'database/migrations/2026_create.php', true],
    ['database/migrations/**', 'database/migrations/nested/dir/file.php', true],
    ['database/migrations/**', 'database/migrations_old/x', false],
    ['**/package.json', 'package.json', true],
    ['**/package.json', 'a/b/package.json', true],
    ['*.lock', 'composer.lock', true],
    ['*.lock', 'dir/composer.lock', false],
    ['file?.txt', 'file1.txt', true],
]);

test('only matching commands run', function () {
    $thread = refreshThread();
    queueRefresh("refresh:\n  composer.lock: composer install\n  package-lock.json: npm ci\n", "composer.lock\nREADME.md\n");

    $results = app(RefreshTriggers::class)->run($thread);

    expect(array_column($results, 'command'))->toBe(['composer install'])
        ->and(execCommands())->toContain(['sh', '-c', 'composer install'])->not->toContain(['sh', '-c', 'npm ci']);
});

test('a command runs once even if several files match', function () {
    $thread = refreshThread();
    queueRefresh("refresh:\n  database/migrations/**: php artisan migrate\n  database/*.sql: php artisan migrate\n", "database/migrations/a.php\ndatabase/migrations/b.php\ndatabase/seed.sql\n");

    expect(app(RefreshTriggers::class)->run($thread))->toHaveCount(1);
});

test('build credentials are in the exec env of refresh commands only', function () {
    Repository::factory()->create(['slug' => 'acme/app', 'build_credentials' => ['NOVA_TOKEN' => 'secret-value']]);
    $thread = refreshThread();
    queueRefresh("refresh:\n  composer.lock: composer install\n", "composer.lock\n");

    app(RefreshTriggers::class)->run($thread);

    $calls = array_column(array_filter(app(SandboxManager::class)->calls, fn (array $call): bool => $call['operation'] === 'exec'), 'arguments');
    $withEnv = array_values(array_filter($calls, fn (array $call): bool => $call['env'] !== []));

    expect($withEnv)->toHaveCount(1)
        ->and($withEnv[0]['command'])->toBe(['sh', '-c', 'composer install'])
        ->and($withEnv[0]['env'])->toBe(['NOVA_TOKEN' => 'secret-value']);
});

test('first run uses all tracked files', function () {
    $thread = refreshThread(['last_refresh_commit' => null]);
    queueRefresh("refresh:\n  composer.lock: composer install\n", "composer.lock\n");

    app(RefreshTriggers::class)->run($thread);

    expect(execCommands())->toContain(['git', 'ls-files'])->not->toContain(['git', 'diff', '--name-only', 'x', 'HEAD']);
});

test('later runs diff against the last refresh commit', function () {
    $thread = refreshThread(['last_refresh_commit' => str_repeat('b', 40)]);
    queueRefresh("refresh:\n  composer.lock: composer install\n", "composer.lock\n");

    app(RefreshTriggers::class)->run($thread);

    expect(execCommands())->toContain(['git', 'diff', '--name-only', str_repeat('b', 40), 'HEAD']);
});

test('success records the new last refresh commit', function () {
    $thread = refreshThread();
    queueRefresh("refresh:\n  composer.lock: composer install\n", "composer.lock\n", [Process::result(''), Process::result(str_repeat('c', 40) . "\n")]);

    app(RefreshTriggers::class)->run($thread);

    expect($thread->fresh()->last_refresh_commit)->toBe(str_repeat('c', 40));
});

test('failure stops the chain and keeps last refresh commit', function () {
    $old = str_repeat('a', 40);
    $thread = refreshThread(['last_refresh_commit' => $old]);
    queueRefresh("refresh:\n  composer.lock: composer install\n  package-lock.json: npm ci\n", "composer.lock\npackage-lock.json\n", [Process::result('', 'boom', 3)]);

    $results = app(RefreshTriggers::class)->run($thread);

    expect($results)->toHaveCount(1)
        ->and($results[0]->exitCode)->toBe(3)
        ->and($results[0]->output)->toContain('boom')
        ->and(execCommands())->not->toContain(['sh', '-c', 'npm ci'])
        ->and($thread->fresh()->last_refresh_commit)->toBe($old);
});

test('requested command must be in the map', function () {
    $thread = refreshThread();
    $sandbox = app(SandboxManager::class);
    $sandbox->queueExecResult(Process::result("refresh:\n  composer.lock: composer install\n"));

    expect(fn () => app(RefreshTriggers::class)->runRequested($thread, 'rm -rf /'))->toThrow(InvalidArgumentException::class);

    $sandbox->queueExecResult(Process::result("refresh:\n  composer.lock: composer install\n"));
    $result = app(RefreshTriggers::class)->runRequested($thread, 'composer install');

    expect($result->successful())->toBeTrue();
});

test('invalid yaml yields no triggers', function () {
    $thread = refreshThread();
    app(SandboxManager::class)->queueExecResult(Process::result("refresh: [unclosed\n  - : :"));

    expect(app(RefreshTriggers::class)->configFor($thread))->toBe([]);
});

test('non string values yield no triggers', function () {
    $thread = refreshThread();
    app(SandboxManager::class)->queueExecResult(Process::result("refresh:\n  composer.lock:\n    - a\n"));

    expect(app(RefreshTriggers::class)->configFor($thread))->toBe([]);
});
