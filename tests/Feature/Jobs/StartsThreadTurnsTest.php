<?php

use App\Contracts\SandboxShell;
use App\DataTransferObjects\SandboxWake;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Jobs\Concerns\StartsThreadTurns;
use App\Models\Repository;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;
use App\Services\Threads\ThreadTurns;
use Illuminate\Foundation\Queue\Queueable;

require_once __DIR__ . '/../../Support/ThreadTurnsHelpers.php';

class StartingJob
{
    use Queueable;
    use StartsThreadTurns;

    public ?string $failure = null;

    public bool $prepared = false;

    public function __construct(public YakTask $task) {}

    public function start(Repository $repository): bool
    {
        return $this->startThreadTurn(
            $repository,
            TaskRunKind::Clarification,
            fn (Thread $thread): string => 'do the work',
            function (SandboxShell $shell, Thread $thread): void {
                $this->prepared = true;
            },
        );
    }

    private function handleError(string $message): void
    {
        $this->failure = $message;
    }
}

beforeEach(function () {
    $this->fakes = fakeTurnCollaborators();
    $this->fakes['commands']->shouldReceive('startRunner', 'turn')->byDefault();
    [$this->thread, $this->task] = threadWithTask();
    $this->thread->update(['sandbox_state' => ThreadSandboxState::Running]);
    $this->repository = Repository::factory()->create(['slug' => $this->task->repo]);
    $this->job = new StartingJob($this->task);
    $this->job->withFakeQueueInteractions();
});

it('prepares the tree and writes the turn, naming its kind', function () {
    $sent = [];
    $this->fakes['commands']->shouldReceive('turn')->once()->andReturnUsing(function ($thread, $uuid, $text) use (&$sent) {
        $sent[] = $text;
    });

    $started = $this->job->start($this->repository);

    $run = TaskRun::query()->where('thread_id', $this->thread->id)->sole();
    expect($started)->toBeTrue()
        ->and($this->job->prepared)->toBeTrue()
        ->and($sent)->toBe(['do the work'])
        ->and($run->kind)->toBe(TaskRunKind::Clarification)
        ->and($run->turn_state)->toBe(TurnState::Running)
        ->and($this->thread->refresh()->append_system_prompt)->not->toBeEmpty();
});

it('releases when another turn runs on the thread', function () {
    app(ThreadTurns::class)->start($this->task, 'first', null, null);

    $started = $this->job->start($this->repository);

    expect($started)->toBeFalse();
    $this->job->assertReleased(30);
    expect($this->task->refresh()->status)->toBe(TaskStatus::Pending);
});

it('releases without starting a turn while the sandbox cannot wake', function () {
    fakeTurnCollaborators(wake: SandboxWake::waitingForSlot());

    $started = $this->job->start($this->repository);

    expect($started)->toBeFalse()
        ->and($this->job->prepared)->toBeFalse()
        ->and(TaskRun::query()->count())->toBe(0);
    $this->job->assertReleased(60);
});

it('leaves a turn that waits for an account to the driver', function () {
    fakeTurnCollaborators(slotFree: false);

    $started = $this->job->start($this->repository);

    expect($started)->toBeTrue()
        ->and(TaskRun::query()->sole()->turn_state)->toBe(TurnState::WaitingForAccount)
        ->and($this->thread->refresh()->blocked_reason)->toBe(ThreadBlockReason::WaitingForAccount);
    $this->job->assertNotReleased();
});

it('cancels the turn and holds the job while a usage limit blocks the thread', function () {
    $this->thread->update(['blocked_reason' => ThreadBlockReason::UsageLimit, 'blocked_at' => now()]);

    $started = $this->job->start($this->repository);

    expect($started)->toBeFalse()
        ->and(TaskRun::query()->sole()->turn_state)->toBe(TurnState::Interrupted)
        ->and($this->task->refresh()->status)->toBe(TaskStatus::Pending);
    $this->job->assertReleased(300);
});

it('fails the task when the account is not usable', function () {
    $this->thread->update(['blocked_reason' => ThreadBlockReason::AccountExpired, 'blocked_at' => now()]);

    $started = $this->job->start($this->repository);

    expect($started)->toBeFalse()
        ->and($this->job->failure)->toContain('account_expired');
    $this->job->assertNotReleased();
});

it('fails the task with the missing-account message when no account is usable', function () {
    app()->forgetInstance(ClaudeAccountResolver::class);
    app()->offsetUnset(ClaudeAccountResolver::class);
    $this->thread->update(['claude_account_id' => null]);

    $started = $this->job->start($this->repository);

    expect($started)->toBeFalse()
        ->and($this->job->failure)->toBe('No Claude account is available. Ask an admin to add a Yak system account.');
});
