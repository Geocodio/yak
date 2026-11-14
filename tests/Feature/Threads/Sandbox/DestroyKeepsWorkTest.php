<?php

use App\Enums\RunnerState;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Services\Threads\AuthProxyPlaceholders;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Tests\Support\LocalGitSandboxManager;

beforeEach(function () {
    Sleep::fake();
    $this->keepTempDir = sys_get_temp_dir() . '/yak-keep-' . uniqid();
    mkdir($this->keepTempDir, 0777, true);
    $this->git = LocalGitSandboxManager::withRepository($this->keepTempDir);
    app()->instance(SandboxManager::class, $this->git);
});

afterEach(fn () => File::deleteDirectory($this->keepTempDir));

function keepWorkThread(LocalGitSandboxManager $git, ThreadSandboxState $state = ThreadSandboxState::Running): Thread
{
    return Thread::factory()->create([
        'sandbox_container' => 'yak-thread-' . fake()->unique()->numberBetween(1, 99999),
        'sandbox_state' => $state,
        'runner_state' => RunnerState::None,
        'branch' => $git->branch,
    ]);
}

function keepWorkOperations(LocalGitSandboxManager $git): array
{
    return array_values(array_filter(array_column($git->calls, 'operation'), fn (string $operation): bool => $operation !== 'exec'));
}

test('destroy pushes uncommitted work before deleting', function () {
    $thread = keepWorkThread($this->git);
    file_put_contents("{$this->git->workspace}/work.txt", 'precious');

    app(ThreadSandbox::class)->destroy($thread);

    expect($this->git->git(['show', "{$this->git->branch}:work.txt"]))->toBe('precious')
        ->and(keepWorkOperations($this->git))->toBe(['destroy'])
        ->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Destroyed);
});

test('destroy without preserving work pushes nothing to the branch', function () {
    $thread = keepWorkThread($this->git);
    $before = $this->git->git(['rev-parse', $this->git->branch]);
    file_put_contents("{$this->git->workspace}/work.txt", 'discarded');

    app(ThreadSandbox::class)->destroy($thread, preserveWork: false);

    expect($this->git->git(['rev-parse', $this->git->branch]))->toBe($before)
        ->and(keepWorkOperations($this->git))->toBe(['destroy'])
        ->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Destroyed);
});

test('a failed wip push blocks the destroy', function () {
    $thread = keepWorkThread($this->git);
    $this->git->rejectPushes();
    file_put_contents("{$this->git->workspace}/work.txt", 'precious');

    app(ThreadSandbox::class)->destroy($thread);

    expect(keepWorkOperations($this->git))->toBe([])
        ->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running);
});

test('eviction stop preserves work before stopping', function () {
    $thread = keepWorkThread($this->git);
    file_put_contents("{$this->git->workspace}/work.txt", 'precious');

    app(ThreadSandbox::class)->stop($thread, true);

    expect($this->git->git(['show', "{$this->git->branch}:work.txt"]))->toBe('precious')
        ->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Stopped);
});

test('a failed wip push keeps an evicted sandbox running', function () {
    $thread = keepWorkThread($this->git);
    $this->git->rejectPushes();
    file_put_contents("{$this->git->workspace}/work.txt", 'precious');

    app(ThreadSandbox::class)->stop($thread, true);

    expect($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running);
});

test('destroy of a stopped sandbox starts it to preserve work', function () {
    $thread = keepWorkThread($this->git, ThreadSandboxState::Stopped);
    file_put_contents("{$this->git->workspace}/work.txt", 'precious');

    app(ThreadSandbox::class)->destroy($thread);

    expect(keepWorkOperations($this->git))->toBe(['start', 'destroy'])
        ->and($this->git->git(['show', "{$this->git->branch}:work.txt"]))->toBe('precious');
});

test('a clean destroy makes no commit', function () {
    $thread = keepWorkThread($this->git);

    app(ThreadSandbox::class)->destroy($thread);

    expect(keepWorkOperations($this->git))->toBe(['destroy'])
        ->and($this->git->git(['branch', '--list', $this->git->branch]))->toBe('')
        ->and($thread->fresh()->wip_commit_sha)->toBeNull();
});

test('destroy revokes the placeholders of every thread in the container', function () {
    $thread = keepWorkThread($this->git);
    $placeholders = Mockery::mock(AuthProxyPlaceholders::class);
    $placeholders->shouldReceive('revoke')->once()->with(Mockery::on(fn ($given) => $given->is($thread)));
    app()->instance(AuthProxyPlaceholders::class, $placeholders);

    app(ThreadSandbox::class)->destroy($thread);
});
