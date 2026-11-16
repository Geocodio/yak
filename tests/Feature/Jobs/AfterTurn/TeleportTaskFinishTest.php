<?php

use App\Enums\NotificationType;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Jobs\AfterTurn\FinishFollowUpTurnJob;
use App\Jobs\AfterTurn\FinishRetryTurnJob;
use App\Jobs\AfterTurn\FinishRunYakTurnJob;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\SendNotificationJob;
use App\Models\YakTask;
use App\Services\SessionStore\SessionSync;
use Illuminate\Support\Facades\Queue;

require_once __DIR__ . '/../../../Support/FinishTurnHelpers.php';

beforeEach(function () {
    Queue::fake();
    $this->collaborators = fakeAfterTurnCollaborators();
    $this->shell = $this->collaborators['shell'];
    $this->sessions = Mockery::mock(SessionSync::class);
    app()->instance(SessionSync::class, $this->sessions);
});

it('a teleport task records cost, syncs the session and notifies without pushing or waiting for CI', function (string $finisher, TaskRunKind $kind) {
    $this->sessions->shouldReceive('syncFromSandbox')->once();
    [$task, $run] = endedTurn(resultEvent('Looked at the code on the laptop branch'), ['source' => YakTask::SOURCE_TELEPORT, 'pr_url' => null], kind: $kind);

    runFinisher($run, $finisher);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Success)
        ->and($task->completed_at)->not->toBeNull()
        ->and((float) $task->cost_usd)->toBe(0.25)
        ->and($this->shell->commandsMatching('git push'))->toBe([]);
    Queue::assertNotPushed(ProcessCIResultJob::class);
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Result);
})->with([
    'initial' => [FinishRunYakTurnJob::class, TaskRunKind::Initial],
    'follow-up' => [FinishFollowUpTurnJob::class, TaskRunKind::FollowUp],
    'retry' => [FinishRetryTurnJob::class, TaskRunKind::Retry],
]);

it('a teleport task that already has a pull request takes the normal push path', function () {
    $this->sessions->shouldReceive('syncFromSandbox')->never();
    [$task, $run] = endedTurn(resultEvent('Fixed it'), ['source' => YakTask::SOURCE_TELEPORT, 'pr_url' => 'https://github.com/acme/web/pull/3', 'pr_number' => 3]);

    runFinisher($run);

    expect($task->refresh()->status)->toBe(TaskStatus::AwaitingCi)
        ->and($this->shell->commandsMatching('git push'))->not->toBe([]);
});

it('only a teleport source is a teleport task', function () {
    expect(YakTask::factory()->make(['source' => 'teleport'])->isTeleport())->toBeTrue()
        ->and(YakTask::factory()->make(['source' => 'slack'])->isTeleport())->toBeFalse();
});
