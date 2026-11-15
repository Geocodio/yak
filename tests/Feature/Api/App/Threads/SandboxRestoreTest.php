<?php

use App\DataTransferObjects\SandboxWake;
use App\Enums\EvictionReason;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Jobs\RetryThreadRestoreJob;
use App\Models\Thread;
use App\Models\ThreadRestoreStep;
use App\Models\User;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\FakeSandboxManager;
use App\Services\Threads\SandboxCapacity;
use App\Services\Threads\SandboxRestorer;
use App\Services\Threads\ThreadRecovery;
use App\Services\Threads\ThreadSandbox;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 10:14:00'));
});

/**
 * @param  array<string, mixed>  $state
 * @return array{0: Thread, 1: User}
 */
function removedSandboxThread(array $state = []): array
{
    $user = User::factory()->create(['name' => 'Test person']);
    $thread = privateThreadOf($user, ['title' => 'Retry failed uploads', 'repo' => 'example/api', 'sandbox_state' => ThreadSandboxState::Destroyed, 'sandbox_container' => null, ...$state]);

    return [$thread, $user];
}

function restoreJobFor(Thread $thread, ?CarbonImmutable $requestedAt = null, int $attempt = 1, ?QueueJob $queueJob = null): RetryThreadRestoreJob
{
    $job = new RetryThreadRestoreJob($thread, $requestedAt ?? $thread->restore_requested_at);
    $queueJob ??= Mockery::mock(QueueJob::class)->shouldReceive('attempts')->andReturn($attempt)->getMock();
    $job->setJob($queueJob);

    return $job;
}

function postRestore(Thread $thread, User $user): TestResponse
{
    return test()->postJson(appThreadPath($thread, '/restore/retry'), [], phoneHeadersFor($user));
}

it('restores a removed sandbox once however often Restore is tapped', function () {
    Queue::fake();
    [$thread, $user] = removedSandboxThread();

    postRestore($thread, $user)->assertOk()->assertExactJson(['status' => 'restoring']);
    postRestore($thread, $user)->assertOk()->assertExactJson(['status' => 'restoring']);
    $this->travel(5)->minutes();
    postRestore($thread, $user)->assertOk()->assertExactJson(['status' => 'restoring']);

    Queue::assertPushed(RetryThreadRestoreJob::class, 1);
    expect($thread->fresh()->restore_requested_at->toDateTimeString())->toBe('2026-11-16 10:14:00');
});

it('lets a new Restore through when the last one died', function () {
    Queue::fake();
    [$thread, $user] = removedSandboxThread(['restore_requested_at' => now()->subMinutes(31)]);

    postRestore($thread, $user)->assertOk();

    Queue::assertPushed(RetryThreadRestoreJob::class, 1);
    expect($thread->fresh()->restore_requested_at->toDateTimeString())->toBe('2026-11-16 10:14:00');
});

it('dispatches nothing while the guard is younger than 30 minutes', function () {
    Queue::fake();
    [$thread, $user] = removedSandboxThread(['restore_requested_at' => now()->subMinutes(29)]);

    postRestore($thread, $user)->assertOk()->assertExactJson(['status' => 'restoring']);

    Queue::assertNothingPushed();
});

it('hands the job the guard it set', function () {
    Queue::fake();
    [$thread, $user] = removedSandboxThread();

    postRestore($thread, $user)->assertOk();

    Queue::assertPushed(RetryThreadRestoreJob::class, fn (RetryThreadRestoreJob $job) => $job->requestedAt?->equalTo($thread->fresh()->restore_requested_at) === true);
});

it('restores a sandbox being removed', function () {
    Queue::fake();
    [$thread, $user] = removedSandboxThread(['sandbox_state' => ThreadSandboxState::Destroying]);

    postRestore($thread, $user)->assertOk();

    Queue::assertPushed(RetryThreadRestoreJob::class, 1);
});

it('has nothing to restore for a running, paused, stopped or never created sandbox', function (ThreadSandboxState $state) {
    Queue::fake();
    [$thread, $user] = removedSandboxThread(['sandbox_state' => $state]);

    postRestore($thread, $user)->assertStatus(409)->assertJsonPath('error', 'nothing_to_restore')->assertJsonPath('message', 'Nothing needs restoring.');

    Queue::assertNothingPushed();
    expect($thread->fresh()->restore_requested_at)->toBeNull();
})->with([
    'running' => ThreadSandboxState::Running,
    'paused' => ThreadSandboxState::Paused,
    'stopped' => ThreadSandboxState::Stopped,
    'none' => ThreadSandboxState::None,
]);

it('refuses on a thread that runs on a computer', function () {
    Queue::fake();
    [$thread, $user] = removedSandboxThread(['owner_side' => ThreadOwnerSide::Device]);

    postRestore($thread, $user)->assertStatus(409)->assertJsonPath('error', 'thread_owned_by_device')->assertJsonPath('message', ThreadRecovery::DEVICE_SENTENCE);

    Queue::assertNothingPushed();
});

it('answers 503 when the thread row is locked too long', function () {
    Queue::fake();
    [$thread] = removedSandboxThread();
    $timeout = new QueryException('sqlite', 'select 1', [], new PDOException('lock wait timeout'));
    $timeout->errorInfo = [null, 1205, 'Lock wait timeout exceeded'];
    Thread::retrieved(fn () => throw $timeout);

    expect(fn () => app(ThreadRecovery::class)->retryRestore($thread))
        ->toThrow(fn (HttpException $exception) => expect($exception->getStatusCode())->toBe(503)->and($exception->getMessage())->toBe('Yak is still handling another request for this thread. Try again in a moment.'));

    Queue::assertNothingPushed();
});

it('clears the guard when the job ends', function (bool $throws) {
    [$thread] = removedSandboxThread(['restore_requested_at' => now()]);
    $mock = $this->mock(ThreadSandbox::class);
    $expectation = $mock->shouldReceive('restoreIfNeeded')->once();
    $throws ? $expectation->andThrow(new RuntimeException('boom')) : $expectation->andReturn(SandboxWake::ready());

    $run = fn () => restoreJobFor($thread)->handle($mock);
    $throws ? expect($run)->toThrow(RuntimeException::class) : $run();

    expect($thread->fresh()->restore_requested_at)->toBeNull();
})->with([false, true]);

it('does nothing in a job for a running, paused or stopped sandbox', function (ThreadSandboxState $state) {
    [$thread] = removedSandboxThread(['sandbox_state' => $state, 'sandbox_container' => 'yak-thread-1', 'restore_requested_at' => now()]);
    $step = ThreadRestoreStep::query()->forceCreate(['thread_id' => $thread->id, 'position' => 1, 'step' => 'create_sandbox', 'status' => 'succeeded', 'finished_at' => now()->subHour()]);
    $before = $step->fresh()->toArray();

    restoreJobFor($thread)->handle(app(ThreadSandbox::class));

    expect(app(SandboxManager::class))->toBeInstanceOf(FakeSandboxManager::class)
        ->and(app(SandboxManager::class)->calls)->toBe([])
        ->and($step->fresh()->toArray())->toBe($before)
        ->and(ThreadRestoreStep::query()->count())->toBe(1)
        ->and($thread->fresh()->restore_requested_at)->toBeNull();
})->with([
    'running' => ThreadSandboxState::Running,
    'paused' => ThreadSandboxState::Paused,
    'stopped' => ThreadSandboxState::Stopped,
]);

it('leaves a newer guard when a stale job ends', function (bool $throws) {
    [$thread] = removedSandboxThread(['restore_requested_at' => now()]);
    $mock = $this->mock(ThreadSandbox::class);
    $expectation = $mock->shouldReceive('restoreIfNeeded')->once();
    $throws ? $expectation->andThrow(new RuntimeException('boom')) : $expectation->andReturn(SandboxWake::ready());
    $stale = restoreJobFor($thread, now()->subMinutes(31));

    $run = fn () => $stale->handle($mock);
    $throws ? expect($run)->toThrow(RuntimeException::class) : $run();

    expect($thread->fresh()->restore_requested_at->toDateTimeString())->toBe('2026-11-16 10:14:00');
})->with([false, true]);

it('waits for a removal that still holds the sandbox', function () {
    [$thread] = removedSandboxThread(['restore_requested_at' => now()]);
    $mock = $this->mock(ThreadSandbox::class);
    $mock->shouldReceive('restoreIfNeeded')->once()->andReturnNull();
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('release')->once()->with(15);

    restoreJobFor($thread, queueJob: $queueJob)->handle($mock);

    expect($thread->fresh()->restore_requested_at)->not->toBeNull();
});

function slotlessRestore(): void
{
    $capacity = test()->mock(SandboxCapacity::class);
    $capacity->shouldReceive('needsEviction')->andReturn(EvictionReason::GlobalCap);
    $capacity->shouldReceive('evictionCandidate')->andReturnNull();
}

it('keeps the guard of a restore that waits for a slot', function () {
    Queue::fake();
    [$thread, $user] = removedSandboxThread(['restore_requested_at' => now()]);
    slotlessRestore();
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn(1);
    $queueJob->shouldReceive('release')->once()->with(30);

    restoreJobFor($thread, queueJob: $queueJob)->handle(app(ThreadSandbox::class));

    expect($thread->fresh()->blocked_reason)->toBe(ThreadBlockReason::WaitingForSandbox)
        ->and($thread->fresh()->restore_requested_at)->not->toBeNull();

    postRestore($thread, $user)->assertOk();
    Queue::assertNothingPushed();
});

it('gives up on a slot after its release budget', function () {
    [$thread] = removedSandboxThread(['restore_requested_at' => now()]);
    slotlessRestore();
    $queueJob = Mockery::mock(QueueJob::class);
    $queueJob->shouldReceive('attempts')->andReturn(21);
    $queueJob->shouldNotReceive('release');

    restoreJobFor($thread, queueJob: $queueJob)->handle(app(ThreadSandbox::class));

    $fresh = $thread->fresh();
    expect($fresh->restore_requested_at)->toBeNull()
        ->and($fresh->blocked_reason)->toBeNull()
        ->and($fresh->sandbox_state)->toBe(ThreadSandboxState::Destroyed);
});

it('runs the restorer for a failed restore on a running sandbox', function () {
    [$thread] = removedSandboxThread(['sandbox_state' => ThreadSandboxState::Running, 'sandbox_container' => 'yak-thread-1', 'blocked_reason' => ThreadBlockReason::RestoreFailed]);
    $restorer = $this->mock(SandboxRestorer::class);
    $restorer->shouldReceive('restore')->once()->andReturnTrue();

    $wake = app(ThreadSandbox::class)->restoreIfNeeded($thread);

    expect($wake?->ready)->toBeTrue();
});

it('runs on the deployments queue with a long timeout', function () {
    [$thread] = removedSandboxThread();
    $job = new RetryThreadRestoreJob($thread, now());

    expect($job->queue)->toBe('yak-deployments')
        ->and($job->timeout)->toBe(900)
        ->and($job->tries)->toBe(21)
        ->and($job->maxExceptions)->toBe(1);
});

it('adds the restore guard column', function () {
    expect(Schema::hasColumn('threads', 'restore_requested_at'))->toBeTrue();
});
