<?php

use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Jobs\AfterTurn\FinishFollowUpTurnJob;
use App\Jobs\AfterTurn\FinishResearchFollowUpTurnJob;
use App\Jobs\AfterTurn\FinishResearchTurnJob;
use App\Jobs\AfterTurn\FinishRetryTurnJob;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\SendNotificationJob;
use Illuminate\Support\Facades\Queue;

require_once __DIR__ . '/../../../Support/FinishTurnHelpers.php';

beforeEach(function () {
    Queue::fake();
    $this->collaborators = fakeAfterTurnCollaborators();
    $this->shell = $this->collaborators['shell'];
});

it('the follow-up finisher pushes and waits for CI', function () {
    [$task, $run] = endedTurn(resultEvent('Renamed the helper'), kind: TaskRunKind::FollowUp);

    runFinisher($run, FinishFollowUpTurnJob::class);

    expect($task->refresh()->status)->toBe(TaskStatus::AwaitingCi)
        ->and($this->shell->commandsMatching('git push --force-with-lease'))->toHaveCount(1);
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Progress);
});

it('the follow-up finisher resolves without a push when there are no commits', function () {
    $this->shell->setCommitCount(0);
    [, $run] = endedTurn(resultEvent('Nothing to change'), kind: TaskRunKind::FollowUp);

    runFinisher($run, FinishFollowUpTurnJob::class);

    expect($this->shell->commandsMatching('git push'))->toBe([]);
    Queue::assertPushed(ProcessCIResultJob::class);
});

it('the follow-up finisher fails the task on an error turn', function () {
    [$task, $run] = endedTurn(resultEvent('It broke', ['is_error' => true, 'subtype' => 'error_during_execution']), kind: TaskRunKind::FollowUp, state: TurnState::Failed);

    runFinisher($run, FinishFollowUpTurnJob::class);

    expect($task->refresh()->status)->toBe(TaskStatus::Failed);
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Error);
});

it('the follow-up finisher consumes the answers it was started with', function () {
    [$task, $run] = endedTurn(kind: TaskRunKind::FollowUp);
    $task->recordClarificationRound([sampleQuestion('scope')], 'Summary');
    $task->recordClarificationAnswers(['scope' => ['choices' => ['Option A'], 'other' => null]], null, 'Michele');

    runFinisher($run, FinishFollowUpTurnJob::class);

    expect($task->fresh()->clarificationAnswersAwaitingResume())->toBeNull();
});

/**
 * @return array<string, mixed>
 */
function askingResult(string $summary = 'One thing.'): array
{
    $block = "```clarification\n" . json_encode(['questions' => [sampleQuestion('scope')->toArray()]]) . "\n```";

    return resultEvent("{$summary}\n\n{$block}");
}

it('a follow-up that asks parks the child task and pushes nothing', function () {
    [$task, $run] = endedTurn(askingResult('Need a call'), kind: TaskRunKind::FollowUp);

    runFinisher($run, FinishFollowUpTurnJob::class);

    expect($task->refresh()->status)->toBe(TaskStatus::AwaitingClarification)
        ->and($this->shell->commandsMatching('git push'))->toBe([]);
    Queue::assertNotPushed(ProcessCIResultJob::class);
});

it('a follow-up on its third round does not park and drops the clarification block', function () {
    [$task, $run] = endedTurn(askingResult("## What changed in this run\n\nDid it."), kind: TaskRunKind::FollowUp);
    foreach (range(1, 3) as $round) {
        $task->recordClarificationRound([sampleQuestion("q{$round}")], 'Summary');
        $task->recordClarificationAnswers(["q{$round}" => ['choices' => ['Option A'], 'other' => null]], null, 'Michele');
        $task->markClarificationAnswersConsumed();
    }

    runFinisher($run, FinishFollowUpTurnJob::class);

    $task->refresh();
    expect($task->status)->not->toBe(TaskStatus::AwaitingClarification)
        ->and($task->result_summary)->not->toContain('```clarification');
});

it('the retry finisher force-pushes and waits for CI', function () {
    [$task, $run] = endedTurn(resultEvent('Fixed the test'), kind: TaskRunKind::Retry);

    runFinisher($run, FinishRetryTurnJob::class);

    expect($task->refresh()->status)->toBe(TaskStatus::AwaitingCi)
        ->and($task->result_summary)->toBe('Fixed the test')
        ->and($this->shell->commandsMatching('git push --force-with-lease origin yak/test-1'))->toHaveCount(1);
});

it('the retry finisher answers without a push when there are no commits', function () {
    $this->shell->setCommitCount(0);
    [$task, $run] = endedTurn(resultEvent('A flake, no change needed'), kind: TaskRunKind::Retry);

    runFinisher($run, FinishRetryTurnJob::class);

    expect($task->refresh()->status)->toBe(TaskStatus::Success)
        ->and($this->shell->commandsMatching('git push'))->toBe([]);
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Result);
});

it('the retry finisher fails the task on an error turn', function () {
    [$task, $run] = endedTurn(resultEvent('Still red', ['is_error' => true, 'subtype' => 'error_during_execution']), kind: TaskRunKind::Retry, state: TurnState::Failed);

    runFinisher($run, FinishRetryTurnJob::class);

    expect($task->refresh()->status)->toBe(TaskStatus::Failed);
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Error);
});

it('the research finisher records the answer, reports it and never pushes', function () {
    [$task, $run] = endedTurn(resultEvent('Three options exist'), ['mode' => TaskMode::Research], kind: TaskRunKind::Research);

    runFinisher($run, FinishResearchTurnJob::class);

    expect($task->refresh()->status)->toBe(TaskStatus::Success)
        ->and($task->result_summary)->toBe('Three options exist')
        ->and($this->shell->commandsMatching('git push'))->toBe([]);
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Result);
});

it('the research finisher fails the task on an error turn', function () {
    [$task, $run] = endedTurn(resultEvent('No luck', ['is_error' => true, 'subtype' => 'error_during_execution']), ['mode' => TaskMode::Research], kind: TaskRunKind::Research, state: TurnState::Failed);

    runFinisher($run, FinishResearchTurnJob::class);

    expect($task->refresh()->status)->toBe(TaskStatus::Failed);
});

it('the research finisher parks the task when the agent asks', function () {
    $block = "```clarification\n" . json_encode(['questions' => [sampleQuestion('scope')->toArray()]]) . "\n```";
    [$task, $run] = endedTurn(resultEvent("One thing.\n\n{$block}"), ['mode' => TaskMode::Research], kind: TaskRunKind::Research);

    runFinisher($run, FinishResearchTurnJob::class);

    expect($task->refresh()->status)->toBe(TaskStatus::AwaitingClarification);
});

it('the research follow-up finisher records the answer and reports it', function () {
    [$task, $run] = endedTurn(resultEvent('Backoff is cheapest'), ['mode' => TaskMode::Research], kind: TaskRunKind::FollowUp);

    runFinisher($run, FinishResearchFollowUpTurnJob::class);

    expect($task->refresh()->status)->toBe(TaskStatus::Success)
        ->and($task->result_summary)->toBe('Backoff is cheapest');
    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Result);
});

it('the listener picks the finisher by kind and task mode', function (TaskRunKind $kind, TaskMode $mode, string $finisher) {
    [, $run] = endedTurn(taskAttributes: ['mode' => $mode], kind: $kind);

    event(new TurnCompleted($run));

    Queue::assertPushed($finisher, fn ($job) => $job->taskRunId === $run->id);
})->with([
    'code follow-up' => [TaskRunKind::FollowUp, TaskMode::Fix, FinishFollowUpTurnJob::class],
    'research follow-up' => [TaskRunKind::FollowUp, TaskMode::Research, FinishResearchFollowUpTurnJob::class],
    'retry' => [TaskRunKind::Retry, TaskMode::Fix, FinishRetryTurnJob::class],
    'research' => [TaskRunKind::Research, TaskMode::Research, FinishResearchTurnJob::class],
]);
