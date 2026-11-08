<?php

use App\Actions\RecordPullRequestOutcome;
use App\Enums\RunnerState;
use App\Enums\TaskStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Enums\TurnState;
use App\Exceptions\SandboxCapacityReached;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadStreams;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Tests\Support\FakeThreadStreams;

beforeEach(function () {
    $this->streams = new FakeThreadStreams;
    $this->app->instance(ThreadStreams::class, $this->streams);
    fakeLifecycleIncus();
    Sleep::fake();
    config()->set('yak.threads.pause_to_stop_minutes', 30);
    config()->set('yak.threads.eviction_grace_minutes', 10);
    config()->set('yak.threads.stop_timeout_seconds', 5);
});

function fakeLifecycleIncus(array $overrides = []): void
{
    // A fresh factory, so a test's overrides win over the default fake from beforeEach.
    Process::swap(new ProcessFactory);
    Process::fake($overrides + [
        'incus info *' => Process::result(exitCode: 1),
        '*systemctl is-system-running*' => Process::result(output: 'running'),
        'incus list *' => Process::result(output: 'RUNNING'),
        '*' => Process::result(),
    ]);
}

function pausedFor(int $minutes, array $attributes = []): Thread
{
    return Thread::factory()->paused()->create($attributes + ['sandbox_state_changed_at' => now()->subMinutes($minutes)]);
}

it('stops sandboxes idle past the pause window', function () {
    $idle = pausedFor(31, ['runner_state' => RunnerState::None]);
    $fresh = pausedFor(5, ['runner_state' => RunnerState::None]);

    Artisan::call('yak:thread-sandboxes');

    expect($idle->fresh()->sandbox_state)->toBe(ThreadSandboxState::Stopped)
        ->and($fresh->fresh()->sandbox_state)->toBe(ThreadSandboxState::Paused);
    Process::assertRan(fn ($p) => str_contains((string) $p->command, "incus stop '{$idle->sandbox_container}'"));
});

it('ends the runner before stopping', function () {
    $thread = pausedFor(31);
    Sleep::whenFakingSleep(fn () => Thread::whereKey($thread->id)->update(['runner_state' => RunnerState::None]));

    app(ThreadSandbox::class)->stop($thread);

    expect($this->streams->events)->toBe(['register:' . $thread->channel(), 'command:end']);
    Process::assertRan("incus resume '{$thread->sandbox_container}'");
    Process::assertRan(fn ($p) => str_contains((string) $p->command, 'incus stop'));
    expect($thread->fresh()->runner_state)->toBe(RunnerState::None);
});

it('forces the stop after the timeout', function () {
    $thread = pausedFor(31);
    fakeLifecycleIncus(['incus stop * --timeout *' => Process::result(exitCode: 1)]);

    app(ThreadSandbox::class)->stop($thread);

    Sleep::assertSleptTimes(5);
    Process::assertRan(fn ($p) => str_contains((string) $p->command, '--force'));
    expect($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Stopped);
});

it('evicts the oldest idle paused sandbox at the cap', function () {
    config()->set('yak.threads.awake_cap', 2);
    $newer = pausedFor(20, ['last_activity_at' => now()->subMinutes(15), 'runner_state' => RunnerState::None]);
    $oldest = pausedFor(20, ['last_activity_at' => now()->subHour(), 'runner_state' => RunnerState::None]);
    $thread = Thread::factory()->stopped()->create();

    app(ThreadSandbox::class)->ensureAwake($thread, Repository::factory()->create(['preview_manifest' => null]));

    expect($oldest->fresh()->sandbox_state)->toBe(ThreadSandboxState::Stopped)
        ->and($newer->fresh()->sandbox_state)->toBe(ThreadSandboxState::Paused)
        ->and($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running);
});

it('starts a waiting turn once a slot frees', function () {
    config()->set('yak.threads.awake_cap', 1);
    $repository = Repository::factory()->create(['preview_manifest' => null]);
    $holder = pausedFor(5, ['runner_state' => RunnerState::None]);
    $waiting = Thread::factory()->stopped()->create();

    expect(fn () => app(ThreadSandbox::class)->ensureAwake($waiting, $repository))->toThrow(SandboxCapacityReached::class);
    expect($waiting->fresh()->blocked_reason)->toBe(ThreadBlockReason::WaitingForSandbox);

    $this->travel(31)->minutes();
    Artisan::call('yak:thread-sandboxes');

    expect($holder->fresh()->sandbox_state)->toBe(ThreadSandboxState::Stopped)
        ->and($waiting->fresh()->blocked_reason)->toBeNull();

    app(ThreadSandbox::class)->ensureAwake($waiting->fresh(), $repository);

    expect($waiting->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running);
});

it('closes the thread when its PR merges', function () {
    $thread = pausedFor(1);
    YakTask::factory()->create(['thread_id' => $thread->id, 'pr_url' => 'https://github.com/acme/app/pull/9']);

    app(RecordPullRequestOutcome::class)->record('https://github.com/acme/app/pull/9', merged: true);

    $thread->refresh();
    expect($thread->status)->toBe(ThreadStatus::Closed)
        ->and($thread->close_reason)->toBe('pr_merged')
        ->and($thread->closed_at)->not->toBeNull()
        ->and($thread->sandbox_state)->toBe(ThreadSandboxState::Destroyed);
    Process::assertRan(fn ($p) => str_contains((string) $p->command, "incus delete {$thread->sandbox_container}"));
});

it('closes idle threads after the destroy window', function () {
    config()->set('yak.threads.destroy_days', 30);
    $idle = Thread::factory()->stopped()->create(['last_activity_at' => now()->subDays(31)]);
    $recent = Thread::factory()->stopped()->create(['last_activity_at' => now()->subDays(2)]);

    Artisan::call('yak:thread-sandboxes');

    expect($idle->fresh()->status)->toBe(ThreadStatus::Closed)
        ->and($idle->fresh()->close_reason)->toBe('idle')
        ->and($recent->fresh()->status)->toBe(ThreadStatus::Open);
});

it('closes finished threads without a PR after the follow-up window', function () {
    config()->set('yak.clarification_ttl_days', 7);
    $finished = Thread::factory()->stopped()->create(['last_activity_at' => now()->subDays(8)]);
    YakTask::factory()->create(['thread_id' => $finished->id, 'status' => TaskStatus::Success, 'pr_url' => null]);
    $withPullRequest = Thread::factory()->stopped()->create(['last_activity_at' => now()->subDays(8)]);
    YakTask::factory()->create(['thread_id' => $withPullRequest->id, 'status' => TaskStatus::Success, 'pr_url' => 'https://github.com/acme/app/pull/1']);

    Artisan::call('yak:thread-sandboxes');

    expect($finished->fresh()->status)->toBe(ThreadStatus::Closed)
        ->and($withPullRequest->fresh()->status)->toBe(ThreadStatus::Open);
});

it('never stops a sandbox while a turn runs', function () {
    $thread = pausedFor(60);
    TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Running]);

    Artisan::call('yak:thread-sandboxes');

    expect($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Paused);
    Process::assertNotRan(fn ($p) => str_contains((string) $p->command, 'incus stop'));
});

it('leaves open thread sandboxes out of stale cleanup', function () {
    fakeLifecycleIncus(['incus list *' => Process::result(output: "yak-thread-1,RUNNING\nyak-thread-2,STOPPED\n")]);

    expect(app(IncusSandboxManager::class)->cleanupStale())->toBe(0);
    Process::assertNotRan(fn ($p) => str_contains((string) $p->command, 'incus delete'));
});
