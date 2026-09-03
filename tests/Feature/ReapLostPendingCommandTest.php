<?php

use App\Enums\TaskStatus;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Jobs\RunYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\AgentJobDispatcher;
use App\Services\HealthCheck\ClaudeAuthCheck;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

test('re-dispatches a stale pending task whose dispatched job has vanished', function () {
    Queue::fake();

    $repository = Repository::factory()->create(['slug' => 'lost-pending-repo']);
    $task = YakTask::factory()->pending()->create([
        'repo' => $repository->slug,
        'dispatched_at' => now()->subMinutes(15),
        'queue_job_uuid' => (string) Str::uuid(),
    ]);

    $this->artisan('yak:reap-lost-pending', ['--minutes' => 10])
        ->assertSuccessful();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Pending)
        ->and($task->dispatched_at)->toBeGreaterThan(now()->subMinute());

    Queue::assertPushed(RunYakJob::class, fn ($job) => $job->task->id === $task->id);
});

test('does not touch a recently dispatched pending task', function () {
    Queue::fake();

    $task = YakTask::factory()->pending()->create([
        'dispatched_at' => now()->subMinutes(2),
        'queue_job_uuid' => (string) Str::uuid(),
    ]);
    // Round-trips through the DB's second-precision timestamp column so
    // the comparison below isn't thrown off by microseconds the column
    // can't store.
    $originalDispatchedAt = $task->fresh()->dispatched_at;

    $this->artisan('yak:reap-lost-pending', ['--minutes' => 10])
        ->assertSuccessful();

    $task->refresh();
    expect($task->dispatched_at->eq($originalDispatchedAt))->toBeTrue();

    Queue::assertNothingPushed();
});

test('does not touch a pending task that was never dispatched (no dispatched_at)', function () {
    Queue::fake();

    $task = YakTask::factory()->pending()->create(['dispatched_at' => null]);

    $this->artisan('yak:reap-lost-pending', ['--minutes' => 10])
        ->assertSuccessful();

    expect($task->fresh()->dispatched_at)->toBeNull();
    Queue::assertNothingPushed();
});

test('does not touch a task that has already started', function () {
    Queue::fake();

    $task = YakTask::factory()->pending()->create([
        'started_at' => now()->subMinutes(30),
        'dispatched_at' => now()->subMinutes(30),
        'queue_job_uuid' => (string) Str::uuid(),
    ]);

    $this->artisan('yak:reap-lost-pending', ['--minutes' => 10])
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('never touches Running, AwaitingCi, Retrying or terminal tasks', function () {
    Queue::fake();

    $factory = fn () => ['dispatched_at' => now()->subHours(2), 'queue_job_uuid' => (string) Str::uuid()];

    YakTask::factory()->running()->create($factory());
    YakTask::factory()->awaitingCi()->create($factory());
    YakTask::factory()->retrying()->create($factory());
    YakTask::factory()->success()->create($factory());
    YakTask::factory()->failed()->create($factory());
    YakTask::factory()->expired()->create($factory());

    $this->artisan('yak:reap-lost-pending', ['--minutes' => 10])
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('does not re-dispatch a task whose job is still sitting in the jobs table', function () {
    config(['queue.default' => 'database']);

    $repository = Repository::factory()->create(['slug' => 'still-queued-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => $repository->slug]);

    // Dispatch for real against the database queue so a row lands in
    // `jobs` carrying this task's queue_job_uuid — then wind the
    // timestamp back so it looks stale to the sweep, without the job
    // actually having vanished.
    app(AgentJobDispatcher::class)->dispatch($task, RunYakJob::class);
    $task->refresh();
    $originalUuid = $task->queue_job_uuid;
    expect($originalUuid)->not->toBeNull();

    $task->forceFill(['dispatched_at' => now()->subMinutes(30)])->save();

    $this->artisan('yak:reap-lost-pending', ['--minutes' => 10])
        ->assertSuccessful();

    $task->refresh();
    // Untouched — the sweep found the original job still queued and
    // skipped re-dispatching it.
    expect($task->queue_job_uuid)->toBe($originalUuid);
    expect(DB::table('jobs')->count())->toBe(1);
});

test('re-dispatches a task whose queue_job_uuid no longer matches anything queued', function () {
    config(['queue.default' => 'database']);

    $repository = Repository::factory()->create(['slug' => 'vanished-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => $repository->slug]);

    app(AgentJobDispatcher::class)->dispatch($task, RunYakJob::class);
    $task->refresh();

    // Simulate the original job vanishing from the queue entirely (the
    // actual 2026-09-03 incident) while queue_job_uuid still points at it.
    DB::table('jobs')->truncate();

    $task->forceFill(['dispatched_at' => now()->subMinutes(30)])->save();

    // The original dispatch's ShouldBeUnique lock (uniqueFor: 900s) is
    // deliberately longer than the sweep's default threshold — by design,
    // see RunYakJob::uniqueFor() — so it would still be held at this
    // point in a real 30-minutes-stale scenario too. But 900s has long
    // since expired by 30 real minutes out; release it here to reflect
    // that instead of leaving the test relying on the lock's TTL not
    // having ticked during the test run.
    (new UniqueLock(app(Illuminate\Contracts\Cache\Repository::class)))
        ->release(new RunYakJob($task));

    Queue::fake();

    $this->artisan('yak:reap-lost-pending', ['--minutes' => 10])
        ->assertSuccessful();

    Queue::assertPushed(RunYakJob::class, fn ($job) => $job->task->id === $task->id);
});

test('is a no-op while the drain flag is set', function () {
    Queue::fake();
    Cache::put(PausesDuringDrain::CACHE_KEY, true, now()->addMinutes(5));

    YakTask::factory()->pending()->create([
        'dispatched_at' => now()->subHours(2),
        'queue_job_uuid' => (string) Str::uuid(),
    ]);

    $this->artisan('yak:reap-lost-pending', ['--minutes' => 10])
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

test('is a no-op while the Claude auth session is unusable', function () {
    Queue::fake();
    Cache::put(ClaudeAuthCheck::UNUSABLE_CACHE_KEY, true, now()->addMinutes(5));

    YakTask::factory()->pending()->create([
        'dispatched_at' => now()->subHours(2),
        'queue_job_uuid' => (string) Str::uuid(),
    ]);

    $this->artisan('yak:reap-lost-pending', ['--minutes' => 10])
        ->assertSuccessful();

    Queue::assertNothingPushed();
});
