<?php

use App\Contracts\SandboxShell;
use App\DataTransferObjects\AgentRunResult;
use App\DataTransferObjects\SandboxWake;
use App\Enums\TaskStatus;
use App\Jobs\AfterTurn\FinishThreadTurnJob;
use App\Models\Repository;
use App\Services\Telemetry\RunRecorder;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\ThreadSandbox;

require_once __DIR__ . '/../../../Support/FinishTurnHelpers.php';

/**
 * A finisher that records what it was asked to do.
 */
class RecordingFinishJob extends FinishThreadTurnJob
{
    /** @var list<string> */
    public static array $events = [];

    public static ?Throwable $throws = null;

    protected function finishTurn(Repository $repository, AgentRunResult $result, SandboxShell $shell, string $container, RunRecorder $recorder): void
    {
        self::$events[] = 'finishTurn:' . $container;

        if (self::$throws !== null) {
            throw self::$throws;
        }
    }

    protected function reportFailure(string $message, ?Throwable $cause = null): void
    {
        self::$events[] = 'reportFailure:' . $message;
        $this->task->update(['status' => TaskStatus::Failed]);
    }

    protected function afterTurn(): void
    {
        self::$events[] = 'afterTurn';
    }
}

beforeEach(function () {
    RecordingFinishJob::$events = [];
    RecordingFinishJob::$throws = null;
});

it('finishes the turn in the thread sandbox, then pauses it and runs afterTurn', function () {
    $collaborators = fakeAfterTurnCollaborators();
    [, $run] = endedTurn();

    runFinisher($run, RecordingFinishJob::class);

    expect(RecordingFinishJob::$events)->toBe(['finishTurn:yak-thread-test', 'afterTurn']);
    $collaborators['sandbox']->shouldHaveReceived('pauseIfIdle')->once();
});

it('releases and does not finish while the sandbox cannot wake', function () {
    $collaborators = fakeAfterTurnCollaborators(SandboxWake::waitingForSlot());
    [$task, $run] = endedTurn();
    $job = new RecordingFinishJob($run->id);
    $job->withFakeQueueInteractions();

    $job->handle(app(ThreadSandbox::class), app(SandboxManager::class));

    $job->assertReleased(60);
    expect(RecordingFinishJob::$events)->toBe(['afterTurn'])
        ->and($task->refresh()->status)->toBe(TaskStatus::Running);
    $collaborators['sandbox']->shouldHaveReceived('pauseIfIdle')->once();
});

it('does not finish a task that is already terminal', function () {
    fakeAfterTurnCollaborators();
    [, $run] = endedTurn(taskAttributes: ['status' => TaskStatus::Cancelled]);

    runFinisher($run, RecordingFinishJob::class);

    expect(RecordingFinishJob::$events)->toBe(['afterTurn']);
});

it('reports a failure raised while finishing and still pauses the sandbox', function () {
    $collaborators = fakeAfterTurnCollaborators();
    RecordingFinishJob::$throws = new RuntimeException('push rejected');
    [$task, $run] = endedTurn();

    runFinisher($run, RecordingFinishJob::class);

    expect(RecordingFinishJob::$events)->toBe(['finishTurn:yak-thread-test', 'reportFailure:push rejected', 'afterTurn'])
        ->and($task->refresh()->status)->toBe(TaskStatus::Failed);
    $collaborators['sandbox']->shouldHaveReceived('pauseIfIdle')->once();
});

it('reports a queue failure through reportFailure', function () {
    fakeAfterTurnCollaborators();
    [$task, $run] = endedTurn();

    (new RecordingFinishJob($run->id))->failed(new RuntimeException('timed out'));

    expect(RecordingFinishJob::$events)->toBe(['reportFailure:timed out'])
        ->and($task->refresh()->status)->toBe(TaskStatus::Failed);
});

it('fails the task when its repository is gone', function () {
    fakeAfterTurnCollaborators();
    [$task, $run] = endedTurn();
    Repository::query()->delete();

    runFinisher($run, RecordingFinishJob::class);

    expect(RecordingFinishJob::$events[0])->toStartWith('reportFailure:Repository');
});
