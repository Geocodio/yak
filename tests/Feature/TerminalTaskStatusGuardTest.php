<?php

use App\Enums\TaskStatus;
use App\Jobs\ResearchYakJob;
use App\Jobs\RetryYakJob;
use App\Jobs\RunFollowUpJob;
use App\Jobs\RunYakJob;
use App\Jobs\RunYakReviewJob;
use App\Jobs\SetupYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use Tests\Support\FakeAgentRunner;

/**
 * Change 1: every inline error handler must preserve an already-terminal
 * task's status/error_log instead of overwriting it with whatever this
 * run's own error happened to be — the exact failure mode from the
 * incident, where a drain's accurate "interrupted after 300s wait"
 * message was clobbered by "stream ended without result event (exit=137)",
 * the abnormal-termination artifact of the interruption itself.
 *
 * @param  class-string  $jobClass
 */
function invokeHandleError(string $jobClass, YakTask $task, string $message): void
{
    $job = new $jobClass($task);
    $method = new ReflectionMethod($job, 'handleError');

    if ($jobClass === SetupYakJob::class) {
        $repository = Repository::where('slug', $task->repo)->first()
            ?? Repository::factory()->create(['slug' => $task->repo]);
        $method->invoke($job, $repository, $message);

        return;
    }

    $method->invoke($job, $message);
}

dataset('job classes with an inline error handler', [
    'RunYakJob' => [RunYakJob::class],
    'ResearchYakJob' => [ResearchYakJob::class],
    'RunYakReviewJob' => [RunYakReviewJob::class],
    'SetupYakJob' => [SetupYakJob::class],
    'RetryYakJob' => [RetryYakJob::class],
    'RunFollowUpJob' => [RunFollowUpJob::class],
]);

test('a task already Failed with a drain message keeps it', function (string $jobClass) {
    $task = YakTask::factory()->failed()->create([
        'error_log' => 'Deploy interrupted the task after 300s wait. Retry it once the deploy is done.',
    ]);

    invokeHandleError($jobClass, $task, 'Claude Code stream ended without result event (lines=418, exit=137)');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toBe('Deploy interrupted the task after 300s wait. Retry it once the deploy is done.');
})->with('job classes with an inline error handler');

test('a non-terminal task is still finalised', function (string $jobClass) {
    $task = YakTask::factory()->running()->create(['error_log' => null]);

    invokeHandleError($jobClass, $task, 'Rate limited by API');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toBe('Rate limited by API')
        ->and($task->completed_at)->not->toBeNull();
})->with('job classes with an inline error handler');

test('cancellation behaviour is unchanged', function (string $jobClass) {
    $task = YakTask::factory()->running()->create();
    $task->update(['status' => TaskStatus::Cancelled, 'error_log' => 'Cancelled by user']);

    invokeHandleError($jobClass, $task, 'stream ended abnormally');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Cancelled)
        ->and($task->error_log)->toBe('Cancelled by user');
})->with('job classes with an inline error handler');

/*
|--------------------------------------------------------------------------
| Early writes (RunYakJob repo-missing, RunYakReviewJob PR-review-disabled)
|--------------------------------------------------------------------------
|
| These run before the atomic claim (Change 0) has a chance to happen —
| checking the repo is the very first thing runTask() does — so they're
| exactly the residual race window described in the spec: a task
| cancelled between dispatch and pickup can still reach here with a
| terminal status by the time the job actually executes.
*/

test('RunYakJob does not overwrite a terminal task when the repo cannot be resolved', function () {
    $task = YakTask::factory()->create([
        'repo' => 'totally-unknown-repo',
        'status' => TaskStatus::Failed,
        'error_log' => 'Deploy interrupted the task after 300s wait.',
        'completed_at' => now(),
    ]);

    (new RunYakJob($task))->handle(new FakeAgentRunner);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toBe('Deploy interrupted the task after 300s wait.');
});

test('RunYakReviewJob does not overwrite a terminal task when PR review is not enabled', function () {
    $repository = Repository::factory()->create(['slug' => 'not-review-enabled', 'pr_review_enabled' => false]);
    $task = YakTask::factory()->create([
        'repo' => $repository->slug,
        'status' => TaskStatus::Cancelled,
        'error_log' => 'Cancelled by user',
    ]);

    (new RunYakReviewJob($task))->handle(new FakeAgentRunner);

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Cancelled)
        ->and($task->error_log)->toBe('Cancelled by user');
});
