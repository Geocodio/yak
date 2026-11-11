<?php

use App\Enums\StackMessageKind;
use App\Enums\StackThreadMessageKind;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadKind;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Mail\HeldEventMail;
use App\Models\StackMessage;
use App\Models\StackThreadMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Stacks\ChildOutcomeRecorder;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeSessionFileSync;

beforeEach(function () {
    app()->instance(SessionFileSync::class, new FakeSessionFileSync);
    $this->sandbox = app(SandboxManager::class);
    $this->parent = Thread::factory()->ownedByDevice()->create();
});

function outcomeChild($test, array $attributes = []): Thread
{
    $child = Thread::factory()->child($test->parent)->create([
        'kind' => ThreadKind::Implement,
        'branch' => 'yak/parent/scout',
        'sandbox_container' => 'yak-thread-child',
        'sandbox_state' => ThreadSandboxState::Running,
        ...$attributes,
    ]);
    YakTask::factory()->create(['thread_id' => $child->id]);

    return $child;
}

function outcomeRun(Thread $child, TurnState $state = TurnState::Completed, string $result = 'Built the widget.'): TaskRun
{
    return TaskRun::factory()->create([
        'thread_id' => $child->id,
        'yak_task_id' => $child->tasks()->first()->id,
        'turn_state' => $state,
        'last_result_event' => ['result' => $result],
    ]);
}

function queuePushResults($test, bool $pushSucceeds): void
{
    $test->sandbox->queueExecResult(Process::result(" M a.php\n"));
    $test->sandbox->queueExecResult(Process::result("1\n"));
    $test->sandbox->queueExecResult(Process::result());
    $test->sandbox->queueExecResult(Process::result());
    $test->sandbox->queueExecResult(Process::result(exitCode: $pushSucceeds ? 0 : 1, errorOutput: 'rejected'));

    if ($pushSucceeds) {
        $test->sandbox->queueExecResult(Process::result("abc123\n"));

        return;
    }

    $test->sandbox->queueExecResult(Process::result(exitCode: 1));
}

it('queues a result with the branch and destroys an implement child sandbox', function () {
    $child = outcomeChild($this);
    queuePushResults($this, true);

    app(ChildOutcomeRecorder::class)->recordFinished($child, outcomeRun($child));

    $row = StackThreadMessage::query()->sole();
    $destroyed = collect($this->sandbox->calls)->contains(fn (array $call): bool => $call['operation'] === 'destroy' && $call['threadId'] === $child->id);

    expect($row->kind)->toBe(StackThreadMessageKind::Result)
        ->and($row->branch)->toBe('yak/parent/scout')
        ->and($row->text)->toBe('Built the widget.')
        ->and($row->queued_at)->not->toBeNull()
        ->and($child->fresh()->result_summary)->toBe('Built the widget.')
        ->and($child->fresh()->sandbox_state)->toBe(ThreadSandboxState::Destroyed)
        ->and($destroyed)->toBeTrue()
        ->and(StackMessage::query()->where('thread_id', $this->parent->id)->sole()->kind)->toBe(StackMessageKind::Result);
});

it('keeps the sandbox and reports a failure when the push fails', function () {
    $child = outcomeChild($this);
    queuePushResults($this, false);

    app(ChildOutcomeRecorder::class)->recordFinished($child, outcomeRun($child));

    $row = StackThreadMessage::query()->sole();

    expect($row->kind)->toBe(StackThreadMessageKind::Failure)
        ->and($row->text)->toContain('could not be pushed')
        ->and($child->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running)
        ->and(collect($this->sandbox->calls)->where('operation', 'destroy'))->toBeEmpty();
});

function queueExtraPush($test, bool $pushSucceeds): void
{
    $test->sandbox->queueExecResult(Process::result(" M b.php\n"));
    $test->sandbox->queueExecResult(Process::result("1\n"));
    $test->sandbox->queueExecResult(Process::result());
    $test->sandbox->queueExecResult(Process::result());
    $test->sandbox->queueExecResult(Process::result(exitCode: $pushSucceeds ? 0 : 1, errorOutput: 'rejected'));
    $test->sandbox->queueExecResult(Process::result($pushSucceeds ? "def456\n" : ''));
}

it('pushes a dirty changes extra before destroying the child sandbox', function () {
    $child = outcomeChild($this);
    ThreadRepository::factory()->changes()->create(['thread_id' => $child->id, 'repo' => 'acme/lib', 'branch' => 'yak/parent/scout', 'sandbox_path' => '/workspace/extras/lib']);
    queuePushResults($this, true);
    queueExtraPush($this, true);

    app(ChildOutcomeRecorder::class)->recordFinished($child, outcomeRun($child));

    $pushedExtra = collect($this->sandbox->calls)->contains(fn (array $call): bool => in_array('/workspace/extras/lib', $call['arguments']['command'] ?? [], true));

    expect(StackThreadMessage::query()->sole()->kind)->toBe(StackThreadMessageKind::Result)
        ->and($child->fresh()->sandbox_state)->toBe(ThreadSandboxState::Destroyed)
        ->and($pushedExtra)->toBeTrue();
});

it('keeps the sandbox when the extra push fails', function () {
    $child = outcomeChild($this);
    ThreadRepository::factory()->changes()->create(['thread_id' => $child->id, 'repo' => 'acme/lib', 'branch' => 'yak/parent/scout', 'sandbox_path' => '/workspace/extras/lib']);
    queuePushResults($this, true);
    queueExtraPush($this, false);

    app(ChildOutcomeRecorder::class)->recordFinished($child, outcomeRun($child));

    expect(StackThreadMessage::query()->sole()->kind)->toBe(StackThreadMessageKind::Failure)
        ->and($child->fresh()->sandbox_state)->toBe(ThreadSandboxState::Running);
});

it('lists mockup artifacts in the result', function () {
    $child = outcomeChild($this, ['kind' => ThreadKind::Mockup]);
    $this->sandbox->queueExecResult(Process::result("home.html\nhome.png\n"));
    queuePushResults($this, true);

    app(ChildOutcomeRecorder::class)->recordFinished($child, outcomeRun($child, result: 'Mocked it.'));

    expect(StackThreadMessage::query()->sole()->text)->toBe("Mocked it.\n\nArtifacts: home.html, home.png");
});

it('queues a research report without touching the container', function () {
    $child = outcomeChild($this, ['kind' => ThreadKind::Research, 'branch' => null, 'sandbox_container' => null]);

    app(ChildOutcomeRecorder::class)->recordFinished($child, outcomeRun($child, result: 'The cache is keyed by user.'));

    $row = StackThreadMessage::query()->sole();

    expect($row->text)->toBe('The cache is keyed by user.')
        ->and($row->branch)->toBeNull()
        ->and($this->sandbox->calls)->toBe([]);
});

it('redacts secrets and caps the summary', function () {
    $child = outcomeChild($this, ['kind' => ThreadKind::Research, 'branch' => null]);

    app(ChildOutcomeRecorder::class)->recordFinished($child, outcomeRun($child, result: 'key sk-ant-abcdefgh12345 ' . str_repeat('x', 5000)));

    $summary = $child->fresh()->result_summary;

    expect($summary)->not->toContain('sk-ant-')->and(mb_strlen($summary))->toBe(4000);
});

it('queues an immediate failure for a guard stop, a usage limit and a crash', function (TurnState $state, ?ThreadBlockReason $reason, string $expected) {
    $child = outcomeChild($this, ['blocked_reason' => $reason]);

    app(ChildOutcomeRecorder::class)->recordFailed($child, outcomeRun($child, $state));

    $row = StackThreadMessage::query()->sole();

    expect($row->kind)->toBe(StackThreadMessageKind::Failure)
        ->and($row->queued_at)->toBeNull()
        ->and($row->delivered_at)->not->toBeNull()
        ->and($row->text)->toContain($expected)->toContain('yak/parent/scout')
        ->and(StackMessage::query()->where('thread_id', $this->parent->id)->sole()->kind)->toBe(StackMessageKind::Failure);
})->with([
    'guard stop' => [TurnState::StoppedByGuard, null, 'runaway guard'],
    'usage limit' => [TurnState::Failed, ThreadBlockReason::UsageLimit, 'usage limit'],
    'crash' => [TurnState::Failed, null, 'crashed'],
]);

it('ignores a stop-stack interrupt, a taken-over child and a stopped stack', function () {
    $recorder = app(ChildOutcomeRecorder::class);
    $child = outcomeChild($this);
    $recorder->recordFailed($child, outcomeRun($child, TurnState::Interrupted));

    $takenOver = outcomeChild($this, ['taken_over_at' => now()]);
    $recorder->recordFailed($takenOver, outcomeRun($takenOver, TurnState::Failed));
    $recorder->recordFinished($takenOver, outcomeRun($takenOver));

    $this->parent->forceFill(['stack_stopped_at' => now()])->save();
    $stopped = outcomeChild($this);
    $recorder->recordFailed($stopped->fresh(), outcomeRun($stopped, TurnState::Failed));

    expect(StackThreadMessage::query()->count())->toBe(0);
});

it('records outcomes from the turn events', function () {
    $child = outcomeChild($this, ['kind' => ThreadKind::Research, 'branch' => null]);
    $run = outcomeRun($child);

    event(new TurnCompleted($run));
    event(new TurnFailed(outcomeRun($child, TurnState::Failed)));

    expect(StackThreadMessage::query()->pluck('kind')->all())->toBe([StackThreadMessageKind::Result, StackThreadMessageKind::Failure]);
});

it('notifies the owner on usage limit with generic text for private threads', function () {
    Mail::fake();
    $owner = User::factory()->create();
    $root = Thread::factory()->private($owner)->ownedByDevice()->create(['title' => 'Secret migration plan']);
    $child = Thread::factory()->child($root)->create(['name' => 'scout', 'blocked_reason' => ThreadBlockReason::UsageLimit]);
    YakTask::factory()->create(['thread_id' => $child->id]);

    app(ChildOutcomeRecorder::class)->recordFailed($child, outcomeRun($child, TurnState::Failed));

    Mail::assertSent(HeldEventMail::class, fn (HeldEventMail $mail): bool => $mail->hasTo($owner->email)
        && ! str_contains($mail->notice, 'Secret migration plan')
        && ! str_contains($mail->notice, 'scout'));
});
