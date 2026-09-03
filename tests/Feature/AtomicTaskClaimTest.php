<?php

use App\Contracts\AgentRunner;
use App\DataTransferObjects\AgentRunResult;
use App\Enums\TaskStatus;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\RunYakReviewJob;
use App\Jobs\SetupYakJob;
use App\Models\DailyCost;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\Process;
use Tests\Support\FakeAgentRunner;
use Tests\Support\FakeSandboxManager;

/**
 * Change 0 (atomic task claim) exercised across all four claiming job
 * classes: RunYakJob, ResearchYakJob, RunYakReviewJob, SetupYakJob.
 *
 * Every other job test in this suite calls ->handle() directly, which
 * bypasses the queue's middleware pipeline entirely — that's fine for
 * testing handle()'s own logic (claimTask() is idempotent and performs
 * the claim itself when no middleware got there first), but it means the
 * "a losing copy never reaches EnsureRepoReady/EnsureDailyBudget" — the
 * actual point of Change 0 — has to be proven by running the real
 * middleware() stack in order, the way CallQueuedHandler would.
 */
function runJobMiddlewarePipeline(array $middleware, object $job, Closure $final): void
{
    $next = $final;

    foreach (array_reverse($middleware) as $mw) {
        $next = fn (object $job) => $mw->handle($job, $next);
    }

    $next($job);
}

dataset('claiming job classes', [
    'RunYakJob' => [RunYakJob::class, 'atomic-claim-run'],
    'ResearchYakJob' => [ResearchYakJob::class, 'atomic-claim-research'],
    'RunYakReviewJob' => [RunYakReviewJob::class, 'atomic-claim-review'],
    'SetupYakJob' => [SetupYakJob::class, 'atomic-claim-setup'],
]);

/**
 * @param  class-string  $jobClass
 */
function makeReadyRepository(string $jobClass, string $slug): Repository
{
    $attributes = ['slug' => $slug, 'path' => "/home/yak/repos/{$slug}"];

    if ($jobClass === RunYakReviewJob::class) {
        $attributes['pr_review_enabled'] = true;
    }

    return Repository::factory()->create($attributes);
}

test('two concurrent copies of one task produce exactly one claim', function (string $jobClass, string $slug) {
    $repository = makeReadyRepository($jobClass, $slug);
    $task = YakTask::factory()->pending()->create(['repo' => $repository->slug, 'attempts' => 0]);

    $copyA = new $jobClass($task);
    $copyB = new $jobClass($task->fresh());

    expect($copyA->claimTask())->toBeTrue()
        ->and($copyB->claimTask())->toBeFalse()
        ->and($copyB->taskClaimLost)->toBeTrue();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Running)
        ->and($task->attempts)->toBe(1);
})->with('claiming job classes');

test('a task already Running is not re-claimed', function (string $jobClass, string $slug) {
    $repository = makeReadyRepository($jobClass, $slug);
    $task = YakTask::factory()->running()->create(['repo' => $repository->slug, 'attempts' => 1]);

    $job = new $jobClass($task);

    expect($job->claimTask())->toBeFalse();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Running)
        ->and($task->attempts)->toBe(1);
})->with('claiming job classes');

test('the losing copy never reaches EnsureDailyBudget and does not mark the task Failed', function (string $jobClass, string $slug) {
    Process::fake(['*' => Process::result('')]);
    config()->set('yak.daily_budget_usd', 10.0);
    DailyCost::factory()->create(['date' => now()->toDateString(), 'total_usd' => 999.0]);

    $repository = makeReadyRepository($jobClass, $slug . '-budget');
    $task = YakTask::factory()->pending()->create(['repo' => $repository->slug]);

    $winner = new $jobClass($task);
    expect($winner->claimTask())->toBeTrue();

    $loser = new $jobClass($task->fresh());
    $reachedHandle = false;

    runJobMiddlewarePipeline($loser->middleware(), $loser, function () use (&$reachedHandle) {
        $reachedHandle = true;
    });

    $task->refresh();

    expect($reachedHandle)->toBeFalse()
        ->and($task->status)->toBe(TaskStatus::Running)
        ->and($task->error_log)->toBeNull()
        ->and($task->completed_at)->toBeNull();
})->with('claiming job classes');

dataset('repo-gated job classes', [
    'RunYakJob' => [RunYakJob::class, 'atomic-claim-repogate-run'],
    'ResearchYakJob' => [ResearchYakJob::class, 'atomic-claim-repogate-research'],
    'RunYakReviewJob' => [RunYakReviewJob::class, 'atomic-claim-repogate-review'],
]);

test('the losing copy never reaches EnsureRepoReady and does not mark the task Failed', function (string $jobClass, string $slug) {
    Process::fake(['*' => Process::result('')]);

    // Not ready — no sandbox snapshot — is exactly what EnsureRepoReady
    // would normally refuse the second copy for. Prove it never gets the
    // chance: the claim middleware deletes the loser first.
    $attributes = ['slug' => $slug, 'sandbox_snapshot' => null];
    if ($jobClass === RunYakReviewJob::class) {
        $attributes['pr_review_enabled'] = true;
    }
    $repository = Repository::factory()->create($attributes);

    $task = YakTask::factory()->pending()->create(['repo' => $repository->slug]);

    $winner = new $jobClass($task);
    expect($winner->claimTask())->toBeTrue();

    $loser = new $jobClass($task->fresh());
    $reachedHandle = false;

    runJobMiddlewarePipeline($loser->middleware(), $loser, function () use (&$reachedHandle) {
        $reachedHandle = true;
    });

    $task->refresh();

    expect($reachedHandle)->toBeFalse()
        ->and($task->status)->toBe(TaskStatus::Running)
        ->and($task->error_log)->toBeNull()
        ->and($task->completed_at)->toBeNull();
})->with('repo-gated job classes');

test('a losing copy does not destroy the winning copy\'s sandbox', function () {
    $fake = (new FakeAgentRunner)->queueResult(new AgentRunResult(
        sessionId: 'sess_race',
        resultSummary: 'Done',
        costUsd: 0.0,
        numTurns: 1,
        durationMs: 1000,
        isError: false,
        clarificationNeeded: false,
        clarificationOptions: [],
        rawOutput: '{}',
    ));
    $this->app->instance(AgentRunner::class, $fake);

    $fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $fakeSandbox);
    Process::fake(['*' => Process::result('')]);

    $repository = Repository::factory()->create(['slug' => 'race-repo', 'path' => '/home/yak/repos/race-repo']);
    $task = YakTask::factory()->pending()->create(['repo' => 'race-repo']);

    // Winner claims and runs to completion — creating and destroying its
    // own sandbox in the process.
    $winner = new RunYakJob($task);
    $winner->handle($fake);

    expect($fakeSandbox->createdContainers)->toHaveCount(1)
        ->and($fakeSandbox->destroyedContainers)->toHaveCount(1);

    // Loser dispatched against the same task after the winner already
    // finished (still the dangerous case: a duplicate that shows up late
    // must not touch the sandbox at all, let alone destroy it).
    $loser = new RunYakJob($task->fresh());
    $loser->handle($fake);

    // No new sandbox activity from the loser.
    expect($fakeSandbox->createdContainers)->toHaveCount(1)
        ->and($fakeSandbox->destroyedContainers)->toHaveCount(1)
        ->and($loser->taskClaimLost)->toBeTrue();
});
