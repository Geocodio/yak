<?php

use App\Contracts\SandboxShell;
use App\DataTransferObjects\AgentRunResult;
use App\DataTransferObjects\SandboxWake;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadLockHolder;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadStatus;
use App\Enums\TurnMessageKind;
use App\Enums\TurnMessageState;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Jobs\AfterTurn\FinishThreadTurnJob;
use App\Listeners\DispatchAfterTurnWork;
use App\Models\ClaudeAccount;
use App\Models\PendingSteeringMessage;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\YakTask;
use App\Services\Telemetry\RunRecorder;
use App\Services\Threads\QueuedMessageRunner;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

require_once __DIR__ . '/../../Support/ThreadTurnHelpers.php';
require_once __DIR__ . '/../../Support/ThreadTurnsHelpers.php';
require_once __DIR__ . '/../../Support/FinishTurnHelpers.php';

/**
 * A finisher with no work of its own.
 */
class IdleFinishJob extends FinishThreadTurnJob
{
    protected function finishTurn(Repository $repository, AgentRunResult $result, SandboxShell $shell, string $container, RunRecorder $recorder): void {}

    protected function reportFailure(string $message, ?Throwable $cause = null): void {}
}

/**
 * A thread whose turn has just completed, with one message queued during it.
 *
 * @return array{0: Thread, 1: TaskRun, 2: YakTask, 3: PendingSteeringMessage}
 */
function completedTurnWithQueuedMessage(string $text = 'Then update the docs', TurnState $state = TurnState::Completed): array
{
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner', 'turn', 'setModel');
    [$thread, $run] = runningTurnFor();
    $task = YakTask::factory()->create(['thread_id' => $thread->id, 'status' => TaskStatus::Running]);
    $thread->forceFill(['runner_model' => 'sonnet', 'model' => 'sonnet'])->save();
    $message = app(ThreadTurns::class)->steer($thread, $text, null, null, null, 'thread', null, true);
    $run->forceFill(['turn_state' => $state, 'finished_at' => now()])->save();
    $thread->forceFill(['lock_holder' => ThreadLockHolder::None, 'lock_turn_id' => null])->save();

    return [$thread, $run->refresh(), $task, $message];
}

function newRunsOf(Thread $thread, TaskRun $finished): int
{
    return TaskRun::query()->where('thread_id', $thread->id)->where('id', '>', $finished->id)->count();
}

it('starts one turn for a queued message when the turn completes', function () {
    [$thread, $run, , $message] = completedTurnWithQueuedMessage();

    $started = app(QueuedMessageRunner::class)->afterTurn($run);

    expect($started)->not->toBeNull()
        ->and($started->prompt)->toBe('- Then update the docs')
        ->and($started->kind)->toBe(TaskRunKind::FollowUp)
        ->and(newRunsOf($thread, $run))->toBe(1)
        ->and(PendingSteeringMessage::query()->whereKey($message->id)->exists())->toBeFalse();
});

it('starts nothing the second time', function () {
    [$thread, $run] = completedTurnWithQueuedMessage();

    expect(app(QueuedMessageRunner::class)->afterTurn($run))->not->toBeNull()
        ->and(app(QueuedMessageRunner::class)->afterTurn($run))->toBeNull()
        ->and(newRunsOf($thread, $run))->toBe(1);
});

it('starts nothing without queued thread messages', function () {
    [$thread, $run, , $message] = completedTurnWithQueuedMessage();
    $message->delete();

    expect(app(QueuedMessageRunner::class)->afterTurn($run))->toBeNull()
        ->and(newRunsOf($thread, $run))->toBe(0);
});

it('starts nothing for a message that is already delivered', function () {
    [$thread, $run, , $message] = completedTurnWithQueuedMessage();
    ThreadTurnMessage::query()->create([
        'task_run_id' => $run->id,
        'uuid' => $message->uuid,
        'kind' => TurnMessageKind::Steer,
        'state' => TurnMessageState::Sent,
        'pending_steering_message_id' => $message->id,
    ]);

    expect(app(QueuedMessageRunner::class)->afterTurn($run))->toBeNull()
        ->and(newRunsOf($thread, $run))->toBe(0)
        ->and(PendingSteeringMessage::query()->whereKey($message->id)->exists())->toBeTrue();
});

it('starts nothing after a failed or interrupted turn', function (TurnState $state) {
    [$thread, $run, , $message] = completedTurnWithQueuedMessage(state: $state);

    expect(app(QueuedMessageRunner::class)->afterTurn($run))->toBeNull()
        ->and(newRunsOf($thread, $run))->toBe(0)
        ->and(PendingSteeringMessage::query()->whereKey($message->id)->exists())->toBeTrue();
})->with([TurnState::Failed, TurnState::Interrupted]);

it('starts nothing on a closed, blocked or device-owned thread, or while the head task waits for answers', function (string $case) {
    [$thread, $run, $task, $message] = completedTurnWithQueuedMessage();

    match ($case) {
        'closed' => $thread->forceFill(['status' => ThreadStatus::Closed])->save(),
        'blocked' => $thread->forceFill(['blocked_reason' => ThreadBlockReason::UsageLimit, 'blocked_at' => now()])->save(),
        'device' => $thread->forceFill(['owner_side' => ThreadOwnerSide::Device])->save(),
        'clarification' => $task->forceFill(['status' => TaskStatus::AwaitingClarification])->save(),
    };

    expect(app(QueuedMessageRunner::class)->afterTurn($run))->toBeNull()
        ->and(newRunsOf($thread, $run))->toBe(0)
        ->and(PendingSteeringMessage::query()->whereKey($message->id)->exists())->toBeTrue();
})->with(['closed', 'blocked', 'device', 'clarification']);

it('starts nothing while another turn runs', function () {
    [$thread, $run, , $message] = completedTurnWithQueuedMessage();
    TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Running, 'claude_account_id' => $run->claude_account_id]);

    expect(app(QueuedMessageRunner::class)->afterTurn($run))->toBeNull()
        ->and(PendingSteeringMessage::query()->whereKey($message->id)->exists())->toBeTrue();
});

it('starts nothing when the lock is held', function () {
    [$thread, $run, , $message] = completedTurnWithQueuedMessage();
    $lock = Cache::lock("thread-queued-start:{$thread->id}", 30);
    $lock->get();

    try {
        $started = app(QueuedMessageRunner::class)->afterTurn($run);
    } finally {
        $lock->release();
    }

    expect($started)->toBeNull()
        ->and(newRunsOf($thread, $run))->toBe(0)
        ->and(PendingSteeringMessage::query()->whereKey($message->id)->exists())->toBeTrue();
});

it('starts the queued turn from the after-turn job', function () {
    $fakes = fakeTurnCollaborators();
    $fakes['commands']->shouldReceive('startRunner', 'turn', 'setModel');
    fakeAfterTurnCollaborators();
    [$task, $run, $thread] = endedTurn();
    $account = ClaudeAccount::factory()->create();
    $thread->forceFill(['claude_account_id' => $account->id, 'model' => 'sonnet'])->save();
    app(ThreadTurns::class)->steer($thread, 'Then update the docs', null, null, null, 'thread', null, true);

    runFinisher($run, IdleFinishJob::class);

    $new = TaskRun::query()->where('thread_id', $thread->id)->where('id', '>', $run->id)->get();
    expect($new)->toHaveCount(1)
        ->and($new->first()->prompt)->toContain('Then update the docs');
});

it('starts nothing while the after-turn job waits for the sandbox', function () {
    fakeAfterTurnCollaborators(new SandboxWake(false, false, false));
    [$task, $run, $thread] = endedTurn();
    $account = ClaudeAccount::factory()->create();
    $thread->forceFill(['claude_account_id' => $account->id, 'model' => 'sonnet'])->save();
    app(ThreadTurns::class)->steer($thread, 'Then update the docs', null, null, null, 'thread', null, true);

    runFinisher($run, IdleFinishJob::class);

    expect(newRunsOf($thread, $run))->toBe(0)
        ->and(PendingSteeringMessage::query()->count())->toBe(1);
});

it('starts the queued turn for a run without a finisher', function () {
    Queue::fake();
    [$thread, $run, $task] = completedTurnWithQueuedMessage();
    $run->forceFill(['kind' => TaskRunKind::Review])->save();

    (new DispatchAfterTurnWork)->handle(new TurnCompleted($run->refresh()));
    (new DispatchAfterTurnWork)->handle(new TurnCompleted($run->refresh()));

    expect(newRunsOf($thread, $run))->toBe(1);
});
