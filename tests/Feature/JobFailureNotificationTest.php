<?php

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\NotificationType;
use App\Enums\StopKind;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\AfterTurn\FinishRunYakTurnJob;
use App\Jobs\ResearchYakJob;
use App\Jobs\RetryYakJob;
use App\Jobs\RunYakReviewJob;
use App\Jobs\SendNotificationJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeAgentRunner;
use Tests\Support\FakeSandboxManager;

require_once __DIR__ . '/../Support/FinishTurnHelpers.php';

function failingAgentResult(): AgentRunResult
{
    return AgentRunResult::failure('Agent blew up', '{}');
}

beforeEach(function () {
    Queue::fake();
    fakeAfterTurnCollaborators();
    $this->app->instance(IncusSandboxManager::class, new FakeSandboxManager);
    Process::fake(['*' => Process::result('')]);
    Repository::factory()->create(['slug' => 'fail-repo', 'path' => '/home/yak/repos/fail-repo']);
});

test('the run finisher tells the source when the turn fails', function () {
    [$task, $run] = endedTurn(false, ['source' => 'linear']);

    runFinisher($run);

    expect($task->refresh()->status)->toBe(TaskStatus::Failed);
    expect($task->refresh()->stop_kind)->toBe(StopKind::YakStopped)
        ->and($task->error_log)->toContain('without a result');
    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Error
        && $job->personalize === false
        && $job->stop?->kind === StopKind::YakStopped
        && str_contains($job->stop->detail, 'without a result'));
});

test('the run finisher tells the source when an exception fails the turn', function () {
    $collaborators = fakeAfterTurnCollaborators();
    $collaborators['shell']->failCommand('git push', 'remote rejected');
    [, $run] = endedTurn(taskAttributes: ['source' => 'linear']);

    runFinisher($run);

    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Error
        && $job->personalize === false
        && str_contains($job->stop->detail, 'remote rejected'));
});

test('a queue failure of the run finisher notifies exactly once', function () {
    [, $run] = endedTurn(taskAttributes: ['source' => 'slack']);

    (new FinishRunYakTurnJob($run->id))->failed(new RuntimeException('timed out'));

    expect(Queue::pushed(SendNotificationJob::class)->filter(
        fn (SendNotificationJob $job): bool => $job->type === NotificationType::Error,
    ))->toHaveCount(1);
});

test('a system task failure does not notify', function () {
    [$task, $run] = endedTurn(false, ['source' => 'system']);

    runFinisher($run);

    expect($task->refresh()->status)->toBe(TaskStatus::Failed);
    Queue::assertNotPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Error);
});

test('a task that is already terminal is not notified again', function () {
    [$task, $run] = endedTurn(taskAttributes: ['source' => 'linear', 'status' => TaskStatus::Cancelled]);

    runFinisher($run);

    expect($task->refresh()->status)->toBe(TaskStatus::Cancelled);
    Queue::assertNotPushed(SendNotificationJob::class);
});

test('RetryYakJob tells the source when the retry fails', function () {
    $fake = (new FakeAgentRunner)->queueResult(failingAgentResult());
    $this->app->instance(AgentRunner::class, $fake);
    $task = YakTask::factory()->retrying()->create(['repo' => 'fail-repo', 'source' => 'linear', 'branch_name' => 'yak/x']);

    (new RetryYakJob($task, 'ci output'))->handle($fake);

    expect($task->stop_kind)->toBe(StopKind::YakStopped);
    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Error
        && $job->personalize === false
        && $job->stop?->kind === StopKind::YakStopped
        && str_contains($job->stop->summary, 'problem on its side'));
});

test('ResearchYakJob tells the source when research fails', function () {
    $fake = (new FakeAgentRunner)->queueResult(failingAgentResult());
    $this->app->instance(AgentRunner::class, $fake);
    $task = YakTask::factory()->pending()->create(['repo' => 'fail-repo', 'source' => 'linear', 'mode' => 'research']);

    (new ResearchYakJob($task))->handle($fake);

    expect($task->stop_kind)->toBe(StopKind::YakStopped);
    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Error
        && $job->personalize === false
        && $job->stop?->kind === StopKind::YakStopped
        && str_contains($job->stop->summary, 'problem on its side'));
});

test('RunYakReviewJob tells the source exactly once when the review fails', function () {
    $fake = (new FakeAgentRunner)->queueResult(failingAgentResult());
    $this->app->instance(AgentRunner::class, $fake);
    Repository::factory()->create(['slug' => 'review-repo', 'path' => '/home/yak/repos/review-repo', 'pr_review_enabled' => true]);
    $task = YakTask::factory()->pending()->create([
        'repo' => 'review-repo',
        'source' => 'github',
        'mode' => TaskMode::Review,
        'pr_url' => 'https://github.com/acme/review-repo/pull/5',
        'context' => json_encode(['pr_number' => 5, 'head_sha' => 'abc', 'base_sha' => 'def', 'review_scope' => 'full']),
    ]);

    (new RunYakReviewJob($task))->handle($fake);

    expect($task->refresh()->status)->toBe(TaskStatus::Failed);
    expect(Queue::pushed(SendNotificationJob::class)->filter(
        fn (SendNotificationJob $job): bool => $job->type === NotificationType::Error,
    ))->toHaveCount(1);
});

test('ResearchYakJob fails cleanly and tells the source when the repo is unresolved', function () {
    $fake = new FakeAgentRunner;
    $this->app->instance(AgentRunner::class, $fake);
    $task = YakTask::factory()->pending()->create(['repo' => 'unknown', 'source' => 'linear', 'mode' => 'research']);

    (new ResearchYakJob($task))->handle($fake);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toContain('Could not determine which repo')
        ->and($task->completed_at)->not->toBeNull();
    expect($task->stop_kind)->toBe(StopKind::NeedsYou);
    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Error
        && $job->personalize === false
        && $job->stop?->kind === StopKind::NeedsYou
        && str_contains($job->stop->ask, 'fail-repo'));
});

test('a budget cap failure tells the source the task was too big', function () {
    $result = new AgentRunResult('', '', 5.0, 80, 1000, true, '{}', 'error_during_execution');

    expect($result->stop()->kind)->toBe(StopKind::NeedsYou)
        ->and((new AgentRunResult('', '', 1.0, 300, 1000, true, '{}', 'error_max_turns'))->stop()->kind)->toBe(StopKind::NeedsYou)
        ->and(failingAgentResult()->stop()->kind)->toBe(StopKind::YakStopped);
});
