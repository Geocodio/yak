<?php

use App\DataTransferObjects\SandboxWake;
use App\DataTransferObjects\ThreadMessageInput;
use App\Enums\MessageDelivery;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Enums\TeleportState;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadKind;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Exceptions\ThreadActionRefused;
use App\Http\Resources\ThreadNoticeData;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Jobs\PrepareTeleportUpJob;
use App\Jobs\RunYakJob;
use App\Jobs\SetupYakJob;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Stacks\ChildSandboxProvisioner;
use App\Services\Threads\ThreadMessageSender;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadStarter;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

require_once __DIR__ . '/../../Teleport/CliTestHelpers.php';
require_once __DIR__ . '/../../../Support/ThreadTurnHelpers.php';
require_once __DIR__ . '/../../../Support/ThreadTurnsHelpers.php';

beforeEach(function () {
    Process::fake(['*' => Process::result(exitCode: 0)]);
    Queue::fake();
    config(['yak.sandbox.base_version' => 10]);
    $this->repository = Repository::factory()->create(['slug' => 'acme/widgets', 'sandbox_base_version' => 9]);
});

function runEnsureRepoReady(YakTask $task): bool
{
    $job = new class
    {
        public ?YakTask $task = null;

        public bool $failed = false;

        public function fail(Throwable $e): void
        {
            $this->failed = true;
        }
    };
    $job->task = $task;
    $reachedJob = false;

    (new EnsureRepoReady)->handle($job, function () use (&$reachedJob) {
        $reachedJob = true;
    });

    expect($job->failed)->toBeFalse();

    return $reachedJob;
}

function startThreadWithFirstMessage(): Thread
{
    $user = User::factory()->create();
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id]);

    return app(ThreadStarter::class)->start($user, 'acme/widgets', TaskMode::Fix, 'Fix the login bug', null, null);
}

it('makes a thread wait on a stale template and dispatches one Setup task', function () {
    $first = startThreadWithFirstMessage();
    $second = startThreadWithFirstMessage();

    foreach ([$first, $second] as $thread) {
        $task = $thread->tasks()->firstOrFail();
        $task->update(['status' => TaskStatus::Running]);

        expect(runEnsureRepoReady($task))->toBeFalse();
        expect($task->fresh()->status)->toBe(TaskStatus::Pending);
        expect($thread->fresh()->blocked_reason)->toBe(ThreadBlockReason::WaitingForRepository);
        expect(ThreadNoticeData::for($thread->fresh())['kind'])->toBe('waiting_for_repository');
    }

    Queue::assertPushed(SetupYakJob::class, 1);
    expect(YakTask::query()->where('mode', TaskMode::Setup)->count())->toBe(1);
    expect($this->repository->fresh()->sandbox_snapshot)->toBeNull();
});

it('starts the first turn with the original message once setup succeeds', function () {
    $thread = startThreadWithFirstMessage();
    $task = $thread->tasks()->firstOrFail();
    $task->update(['status' => TaskStatus::Running]);
    runEnsureRepoReady($task);
    Queue::assertPushed(RunYakJob::class, 1);
    // The first job has ended, so its uniqueness lock is gone.
    Queue::releaseUniqueJobLocks();

    $this->repository->update(['setup_status' => 'running']);
    $this->repository->update(['setup_status' => 'ready', 'sandbox_snapshot' => 'yak-tpl/ready', 'sandbox_base_version' => 10]);

    expect($thread->fresh()->blocked_reason)->toBeNull();
    Queue::assertPushed(RunYakJob::class, 2);
    expect($task->fresh()->description)->toBe('Fix the login bug')->and($task->fresh()->status)->toBe(TaskStatus::Pending);
});

it('does not clone a stale template when a teleport up restores the sandbox', function () {
    $thread = Thread::factory()->destroyed()->create(['repo' => 'acme/widgets', 'branch' => 'yak/up']);
    [$device] = makeDevice();
    $teleport = Teleport::factory()->up()->inState(TeleportState::Preparing)->create([
        'thread_id' => $thread->id,
        'device_id' => $device->id,
        'session_id' => 'aaaaaaaa-0000-4000-8000-000000000001',
        'payload' => ['prepare_started_at' => now()->toIso8601String()],
    ]);

    $job = new PrepareTeleportUpJob($teleport->id);
    $job->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    $job->assertReleased((int) config('yak.teleport.slot_poll_seconds', 5));
    expect($teleport->refresh()->state)->toBe(TeleportState::Preparing);
    expect($thread->fresh()->sandbox_state)->toBe(ThreadSandboxState::Destroyed);
    expect($thread->fresh()->blocked_reason)->toBe(ThreadBlockReason::WaitingForRepository);
    Queue::assertPushed(SetupYakJob::class, 1);
});

it('does not clone while a setup is already rebuilding the template', function () {
    $this->repository->update(['sandbox_snapshot' => null, 'sandbox_base_version' => null, 'setup_status' => 'running']);
    $thread = Thread::factory()->destroyed()->create(['repo' => 'acme/widgets']);

    $wake = app(ThreadSandbox::class)->ensureAwake($thread);

    expect($wake->ready)->toBeFalse()->and($wake->restoring)->toBeTrue();
    Queue::assertNotPushed(SetupYakJob::class);
});

it('keeps the repository wait on a turn whose sandbox waits for the template', function () {
    [$thread, $task] = threadWithTask();
    $thread->forceFill(['blocked_reason' => ThreadBlockReason::WaitingForRepository, 'blocked_at' => now()])->save();
    $fakes = fakeTurnCollaborators(wake: SandboxWake::restoring());
    $fakes['commands']->shouldNotReceive('turn');

    $run = app(ThreadTurns::class)->start($task, 'go', null, null);

    expect($run->turn_state)->toBe(TurnState::WaitingForSandbox)
        ->and($thread->refresh()->blocked_reason)->toBe(ThreadBlockReason::WaitingForRepository);
});

it('queues a message on a thread that has already run while its repository is rebuilt', function () {
    [$thread] = runningTurnFor();
    YakTask::factory()->create(['thread_id' => $thread->id]);
    $thread->forceFill(['runner_model' => 'sonnet', 'model' => 'sonnet', 'blocked_reason' => ThreadBlockReason::WaitingForRepository, 'blocked_at' => now()])->save();

    $result = app(ThreadMessageSender::class)->send($thread, User::factory()->create(), new ThreadMessageInput('Use the staging bucket', null, null, null, MessageDelivery::AfterTurn, null));

    expect($result->status)->toBe('queued');
});

it('still refuses a message on a thread that never started', function () {
    $thread = Thread::factory()->create(['blocked_reason' => ThreadBlockReason::WaitingForRepository]);
    YakTask::factory()->create(['thread_id' => $thread->id]);

    expect(fn () => app(ThreadMessageSender::class)->send($thread, User::factory()->create(), new ThreadMessageInput('hi', null, null, null, MessageDelivery::Steer, null)))
        ->toThrow(ThreadActionRefused::class, 'This thread has not started yet.');
});

it('does not clone a stale template for a child in another repository', function () {
    Repository::factory()->create(['slug' => 'acme/other', 'sandbox_base_version' => 9]);
    $parent = Thread::factory()->create(['repo' => 'acme/widgets', 'sandbox_container' => 'yak-thread-parent', 'sandbox_state' => ThreadSandboxState::Running]);
    $child = Thread::factory()->child($parent)->create(['kind' => ThreadKind::Implement, 'repo' => 'acme/other']);

    app(ChildSandboxProvisioner::class)->provision($child);

    $child->refresh();
    expect($child->sandbox_state)->toBe(ThreadSandboxState::None)
        ->and($child->queued_for_slot_at)->not->toBeNull()
        ->and($child->blocked_reason)->toBe(ThreadBlockReason::WaitingForRepository);
    Queue::assertPushed(SetupYakJob::class, 1);
});
