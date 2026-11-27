<?php

use App\Enums\TaskRunKind;
use App\Enums\TaskStatus;
use App\Jobs\AfterTurn\FinishRetryTurnJob;
use App\Jobs\Middleware\EnsureDailyBudget;
use App\Jobs\Middleware\EnsureRepoReady;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\RetryYakJob;
use App\Models\GitHubInstallationToken;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;
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

test('a retry stores the ci diagnosis and keeps it out of the pull request summary, and a new retry clears it', function () {
    $task = retryTask(['ci_diagnosis' => ['why' => 'Old.', 'next' => 'Old.']]);
    $task->update(['status' => TaskStatus::AwaitingCi]);
    $task->update(['status' => TaskStatus::Retrying]);

    expect($task->fresh()->ci_diagnosis)->toBeNull();

    finishTaskTurn(FinishRetryTurnJob::class, $task, new FakeSandboxManager, "Fixed what I could.\n\n```ci_diagnosis\n{\"why\": \"The audit fails.\", \"next\": \"Upgrade it.\"}\n```", TaskRunKind::Retry);

    expect($task->fresh()->ci_diagnosis)->toBe(['why' => 'The audit fails.', 'next' => 'Upgrade it.'])
        ->and($task->fresh()->result_summary)->toBe('Fixed what I could.');
});

function unchangedRetryTask(): YakTask
{
    config()->set('yak.channels.github.installation_id', 99999);
    GitHubInstallationToken::factory()->create(['installation_id' => 99999, 'token' => 'ghs_test_token', 'expires_at' => now()->addHour()]);
    Repository::factory()->create(['slug' => 'org/flaky', 'github_full_name' => 'org/flaky', 'ci_system' => 'github_actions']);

    return retryTask(['repo' => 'org/flaky', 'attempts' => 2]);
}

test('a retry that leaves the branch unchanged reruns the failed CI jobs instead of waiting', function () {
    $task = unchangedRetryTask();
    Http::fake([
        'api.github.com/repos/org/flaky/actions/runs/*/rerun-failed-jobs' => Http::response([], 201),
        'api.github.com/repos/org/flaky/branches/*' => Http::response(['commit' => ['sha' => 'abc']]),
        'api.github.com/repos/org/flaky/actions/runs*' => Http::response(['workflow_runs' => [
            ['id' => 7, 'head_sha' => 'abc', 'status' => 'completed', 'conclusion' => 'failure'],
        ]]),
    ]);
    $sandbox = (new FakeSandboxManager)->setBranchDiffCount(0);

    finishTaskTurn(FinishRetryTurnJob::class, $task, $sandbox, 'Browser failures look like flakes.', TaskRunKind::Retry);

    expect($task->fresh()->status)->toBe(TaskStatus::AwaitingCi)
        ->and($sandbox->commandsMatching('git push'))->toBe([]);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/actions/runs/7/rerun-failed-jobs'));
});

test('a retry that leaves the branch unchanged fails at once when no CI jobs can rerun', function () {
    $task = unchangedRetryTask();
    Http::fake([
        'api.github.com/repos/org/flaky/branches/*' => Http::response(['commit' => ['sha' => 'abc']]),
        'api.github.com/repos/org/flaky/actions/runs*' => Http::response(['workflow_runs' => []])]);

    finishTaskTurn(FinishRetryTurnJob::class, $task, (new FakeSandboxManager)->setBranchDiffCount(0), 'Nothing to change.', TaskRunKind::Retry);

    expect($task->fresh()->status)->toBe(TaskStatus::Failed)
        ->and($task->fresh()->error_log)->toContain('Retry made no changes');
});

test('a retry that leaves the branch unchanged finishes when every failing check also fails on the default branch', function () {
    $task = unchangedRetryTask();
    Http::fake([
        'api.github.com/repos/org/flaky/commits/yak/ISSUE-100/check-runs*' => Http::response(['check_runs' => [
            ['name' => 'Security Audit', 'conclusion' => 'failure'],
            ['name' => 'tests', 'conclusion' => 'success'],
        ]]),
        'api.github.com/repos/org/flaky/commits/main/check-runs*' => Http::response(['check_runs' => [
            ['name' => 'Security Audit', 'conclusion' => 'failure'],
        ]]),
    ]);

    finishTaskTurn(FinishRetryTurnJob::class, $task, (new FakeSandboxManager)->setBranchDiffCount(0), 'The audit fails on main too.', TaskRunKind::Retry);

    expect($task->fresh()->status)->toBe(TaskStatus::Success)
        ->and($task->fresh()->result_summary)->toBe('The audit fails on main too.');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'rerun-failed-jobs'));
});

test('a retry that leaves the branch unchanged still reruns when a failing check passes on the default branch', function () {
    $task = unchangedRetryTask();
    Http::fake([
        'api.github.com/repos/org/flaky/commits/yak/ISSUE-100/check-runs*' => Http::response(['check_runs' => [
            ['name' => 'Security Audit', 'conclusion' => 'failure'],
            ['name' => 'browser', 'conclusion' => 'failure'],
        ]]),
        'api.github.com/repos/org/flaky/commits/main/check-runs*' => Http::response(['check_runs' => [
            ['name' => 'Security Audit', 'conclusion' => 'failure'],
            ['name' => 'browser', 'conclusion' => 'success'],
        ]]),
        'api.github.com/repos/org/flaky/actions/runs/*/rerun-failed-jobs' => Http::response(['message' => 'Unable to rerun'], 403),
        'api.github.com/repos/org/flaky/branches/*' => Http::response(['commit' => ['sha' => 'abc']]),
        'api.github.com/repos/org/flaky/actions/runs*' => Http::response(['workflow_runs' => [
            ['id' => 7, 'head_sha' => 'abc', 'status' => 'completed', 'conclusion' => 'failure'],
        ]]),
    ]);

    finishTaskTurn(FinishRetryTurnJob::class, $task, (new FakeSandboxManager)->setBranchDiffCount(0), 'Browser failures look like flakes.', TaskRunKind::Retry);

    expect($task->fresh()->status)->toBe(TaskStatus::Failed)
        ->and($task->fresh()->error_log)->toContain('Retry made no changes');
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/actions/runs/7/rerun-failed-jobs'));
});

test('a retry that leaves a green branch without a pull request opens the pull request', function () {
    $task = unchangedRetryTask();
    Http::fake([
        'api.github.com/repos/org/flaky/branches/*' => Http::response(['commit' => ['sha' => 'abc']]),
        'api.github.com/repos/org/flaky/actions/runs*' => Http::response(['workflow_runs' => [
            ['id' => 7, 'head_sha' => 'abc', 'status' => 'completed', 'conclusion' => 'success'],
        ]])]);

    finishTaskTurn(FinishRetryTurnJob::class, $task, (new FakeSandboxManager)->setBranchDiffCount(0), 'The work is already on the branch.', TaskRunKind::Retry);

    expect($task->fresh()->status)->toBe(TaskStatus::AwaitingCi);
    Queue::assertPushed(ProcessCIResultJob::class, fn (ProcessCIResultJob $job): bool => $job->passed);
});

test('a retry runs on the yak-claude queue with the budget and repo middleware', function () {
    $job = new RetryYakJob(YakTask::factory()->retrying()->make());
    $classes = array_map(fn ($middleware) => $middleware::class, $job->middleware());

    expect($job->queue)->toBe('yak-claude')
        ->and($classes)->toContain(EnsureDailyBudget::class)->toContain(EnsureRepoReady::class);
});
