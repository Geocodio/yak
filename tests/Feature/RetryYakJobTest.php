<?php

use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Jobs\AfterTurn\FinishRetryTurnJob;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Jobs\RetryYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSandboxManager;

require_once __DIR__ . '/../Support/FinishTurnHelpers.php';

beforeEach(function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'retry-repo', 'path' => '/home/yak/repos/retry-repo']);
});

function retryTask(array $attributes = []): YakTask
{
    return YakTask::factory()->retrying()->create(['repo' => 'retry-repo', 'branch_name' => 'yak/ISSUE-100', ...$attributes]);
}

test('a retry accumulates cost and turns on the task', function () {
    $task = retryTask(['cost_usd' => 3.00, 'num_turns' => 20, 'duration_ms' => 150000]);

    finishTaskTurn(FinishRetryTurnJob::class, $task, new FakeSandboxManager, resultEvent('Done', ['total_cost_usd' => 0.75, 'num_turns' => 5, 'duration_ms' => 30000]), TaskRunKind::Retry);

    expect($task->refresh()->status)->toBe(TaskStatus::AwaitingCi)
        ->and((float) $task->cost_usd)->toBeGreaterThan(3.0);
});

test('a successful retry clears a stale pr_body_update from the failed attempt', function () {
    $task = retryTask(['pr_body_update' => "## Summary\n\nFailed attempt's rewrite.", 'review_replies' => [1 => 'stale']]);

    finishTaskTurn(FinishRetryTurnJob::class, $task, new FakeSandboxManager, 'Fixed the CI failures', TaskRunKind::Retry);

    expect($task->fresh()->pr_body_update)->toBeNull()
        ->and($task->fresh()->review_replies)->toBe([1 => 'stale']);
});

test('a retry without new commits succeeds and clears a stale pr_body_update', function () {
    $task = retryTask(['pr_body_update' => 'stale rewrite']);

    finishTaskTurn(FinishRetryTurnJob::class, $task, (new FakeSandboxManager)->setCommitCount(0), 'No code change needed.', TaskRunKind::Retry);

    expect($task->fresh()->status)->toBe(TaskStatus::Success)
        ->and($task->fresh()->pr_body_update)->toBeNull();
});

test('a retry runs on the yak-claude queue with the budget and repo middleware', function () {
    $job = new RetryYakJob(YakTask::factory()->retrying()->make());
    $classes = array_map(fn ($middleware) => $middleware::class, $job->middleware());

    expect($job->queue)->toBe('yak-claude')
        ->and($classes)->toContain(EnsureDailyBudget::class)->toContain(EnsureRepoReady::class);
});
