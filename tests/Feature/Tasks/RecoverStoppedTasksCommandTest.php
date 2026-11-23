<?php

use App\Enums\StopKind;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\Middleware\PausesDuringDrain;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\RetryYakJob;
use App\Jobs\RunYakJob;
use App\Models\GitHubInstallationToken;
use App\Models\Repository;
use App\Models\TaskLog;
use App\Models\YakTask;
use App\Services\Tasks\TaskActions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    config()->set('yak.channels.github.installation_id', 99999);
    GitHubInstallationToken::factory()->create(['installation_id' => 99999, 'token' => 'ghs_test_token', 'expires_at' => now()->addHour()]);
    Repository::factory()->create(['slug' => 'example/api', 'github_full_name' => 'example/api', 'ci_system' => 'github_actions']);
});

function yakStoppedTask(array $attributes = []): YakTask
{
    return YakTask::factory()->failed()->create($attributes + [
        'repo' => 'example/api',
        'mode' => TaskMode::Fix,
        'stop_kind' => StopKind::YakStopped,
        'branch_name' => 'yak/upgrade-sdk',
        'pr_url' => null,
        'attempts' => 2,
        'error_log' => 'Push rejected',
        'completed_at' => now()->subHour(),
    ]);
}

/**
 * @param  list<array{status: string, conclusion: ?string}>  $runs
 */
function fakeRecoveryGitHub(?string $headSha, array $runs = [], int $branchStatus = 200): void
{
    Http::fake([
        'api.github.com/repos/example/api/branches/*' => $headSha === null
            ? Http::response([], $branchStatus === 200 ? 404 : $branchStatus)
            : Http::response(['commit' => ['sha' => $headSha]]),
        'api.github.com/repos/example/api/actions/runs/*/rerun-failed-jobs' => Http::response([], 201),
        'api.github.com/repos/example/api/actions/runs*' => Http::response(['workflow_runs' => array_map(
            fn (array $run, int $index): array => ['id' => 10 + $index, 'head_sha' => 'head111'] + $run,
            $runs,
            array_keys($runs),
        )]),
    ]);
}

function assertNoRecoveryAgentJobs(): void
{
    Queue::assertNotPushed(RetryYakJob::class);
    Queue::assertNotPushed(RunYakJob::class);
}

it('opens the pull request without an agent when the branch is on GitHub and CI is green', function () {
    fakeRecoveryGitHub('head111', [['status' => 'completed', 'conclusion' => 'success']]);
    $task = yakStoppedTask();

    $this->artisan('yak:recover-stopped-tasks')->assertSuccessful();

    Queue::assertPushed(ProcessCIResultJob::class, fn (ProcessCIResultJob $job) => $job->task->is($task) && $job->passed && $job->commitSha === 'head111');
    assertNoRecoveryAgentJobs();
    expect($task->fresh()->status)->toBe(TaskStatus::AwaitingCi)
        ->and($task->fresh()->auto_recovery_count)->toBe(1)
        ->and(TaskLog::where('yak_task_id', $task->id)->where('message', 'Recovered automatically: opening the pull request (CI green on head111)')->exists())->toBeTrue();
});

it('does nothing while CI is still running on the branch', function () {
    fakeRecoveryGitHub('head111', [['status' => 'in_progress', 'conclusion' => null]]);
    $task = yakStoppedTask();

    $this->artisan('yak:recover-stopped-tasks')->assertSuccessful();

    Queue::assertNothingPushed();
    expect($task->fresh()->status)->toBe(TaskStatus::Failed)->and($task->fresh()->auto_recovery_count)->toBe(0);
});

it('reruns the failed jobs once for a red branch without spending the budget', function () {
    fakeRecoveryGitHub('head111', [['status' => 'completed', 'conclusion' => 'failure']]);
    $task = yakStoppedTask();

    $this->artisan('yak:recover-stopped-tasks')->assertSuccessful();

    Http::assertSentCount(3);
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/actions/runs/10/rerun-failed-jobs'));
    Queue::assertNothingPushed();
    expect($task->fresh()->status)->toBe(TaskStatus::AwaitingCi)
        ->and($task->fresh()->auto_recovery_count)->toBe(0)
        ->and(TaskLog::where('yak_task_id', $task->id)->where('message', 'Recovered automatically: re-running failed CI jobs')->exists())->toBeTrue();
});

it('retries once on the branch when the same head is still red after the rerun', function () {
    fakeRecoveryGitHub('head111', [['status' => 'completed', 'conclusion' => 'failure']]);
    $task = yakStoppedTask();

    $this->artisan('yak:recover-stopped-tasks')->assertSuccessful();
    $task->refresh()->update(['status' => TaskStatus::Failed, 'stop_kind' => StopKind::YakStopped, 'error_log' => 'Lint failed', 'completed_at' => now()]);
    Queue::fake();

    $this->artisan('yak:recover-stopped-tasks')->assertSuccessful();

    Queue::assertPushed(RetryYakJob::class, 1);
    Queue::assertNotPushed(RunYakJob::class);
    expect($task->fresh()->status)->toBe(TaskStatus::Retrying)
        ->and($task->fresh()->auto_recovery_count)->toBe(1)
        ->and(TaskLog::where('yak_task_id', $task->id)->where('message', 'Recovered automatically: retrying on the branch')->exists())->toBeTrue();

    // Failing again after the retry leaves it for a person.
    $task->refresh()->update(['status' => TaskStatus::Failed, 'stop_kind' => StopKind::YakStopped, 'completed_at' => now()]);
    Queue::fake();

    $this->artisan('yak:recover-stopped-tasks')->assertSuccessful();

    Queue::assertNothingPushed();
    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
});

it('starts one fresh retry when the branch is not on GitHub and then leaves the task alone', function () {
    fakeRecoveryGitHub(null);
    $task = yakStoppedTask();

    $this->artisan('yak:recover-stopped-tasks')->assertSuccessful();

    Queue::assertPushed(RunYakJob::class, 1);
    Queue::assertNotPushed(RetryYakJob::class);
    expect($task->fresh()->status)->toBe(TaskStatus::Pending)
        ->and($task->fresh()->auto_recovery_count)->toBe(1)
        ->and(TaskLog::where('yak_task_id', $task->id)->where('message', 'Recovered automatically: starting a fresh retry')->exists())->toBeTrue();

    $task->refresh()->update(['status' => TaskStatus::Failed, 'stop_kind' => StopKind::YakStopped, 'completed_at' => now()]);
    Queue::fake();

    $this->artisan('yak:recover-stopped-tasks')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('starts a fresh retry for a task that never recorded a branch without asking GitHub', function () {
    Http::fake();
    yakStoppedTask(['branch_name' => null]);

    $this->artisan('yak:recover-stopped-tasks')->assertSuccessful();

    Queue::assertPushed(RunYakJob::class, 1);
    Http::assertNothingSent();
});

it('does nothing when GitHub cannot say whether the branch exists', function () {
    fakeRecoveryGitHub(null, branchStatus: 500);
    $task = yakStoppedTask();

    $this->artisan('yak:recover-stopped-tasks')->assertSuccessful();

    Queue::assertNothingPushed();
    expect($task->fresh()->status)->toBe(TaskStatus::Failed)->and($task->fresh()->auto_recovery_count)->toBe(0);
});

it('does nothing when GitHub cannot list the CI runs', function () {
    Http::fake([
        'api.github.com/repos/example/api/branches/*' => Http::response(['commit' => ['sha' => 'head111']]),
        'api.github.com/repos/example/api/actions/runs*' => Http::response([], 502),
    ]);
    $task = yakStoppedTask();

    $this->artisan('yak:recover-stopped-tasks')->assertSuccessful();

    Queue::assertNothingPushed();
    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
});

it('does nothing when the branch has no CI runs', function () {
    fakeRecoveryGitHub('head111', []);
    $task = yakStoppedTask();

    $this->artisan('yak:recover-stopped-tasks')->assertSuccessful();

    Queue::assertNothingPushed();
    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
});

it('leaves a task alone when a person retries it before the claim', function () {
    fakeRecoveryGitHub('head111', [['status' => 'completed', 'conclusion' => 'success']]);
    $task = yakStoppedTask();
    // The sweep read the task as Failed; by the claim a person's Retry has moved it on.
    $task->newQuery()->whereKey($task->id)->update(['status' => TaskStatus::Retrying->value]);

    $outcome = app(TaskActions::class)->recoverStoppedTask($task);

    expect($outcome)->toBe('skipped');
    Queue::assertNothingPushed();
});

it('skips a task that is not a candidate', function (array $attributes) {
    fakeRecoveryGitHub('head111', [['status' => 'completed', 'conclusion' => 'success']]);
    $task = yakStoppedTask($attributes);

    $this->artisan('yak:recover-stopped-tasks')->assertSuccessful();

    Queue::assertNothingPushed();
    expect($task->fresh()->auto_recovery_count)->toBe($attributes['auto_recovery_count'] ?? 0);
})->with([
    'needs you' => [['stop_kind' => StopKind::NeedsYou]],
    'yak retrying' => [['stop_kind' => StopKind::YakRetrying]],
    'cancelled' => [['status' => TaskStatus::Cancelled]],
    'success' => [['status' => TaskStatus::Success]],
    'expired' => [['status' => TaskStatus::Expired]],
    'has a pull request' => [['pr_url' => 'https://github.com/example/api/pull/9']],
    'targets an external pull request' => [['targets_external_pr' => true]],
    'research task' => [['mode' => TaskMode::Research]],
    'older than the window' => [['completed_at' => now()->subHours(25)]],
    'budget spent' => [['auto_recovery_count' => 1]],
]);

it('uses the last update when a task has no completion time and honours the configured window', function () {
    fakeRecoveryGitHub('head111', [['status' => 'completed', 'conclusion' => 'success']]);
    $recent = yakStoppedTask(['completed_at' => null]);
    $old = yakStoppedTask(['completed_at' => now()->subHours(30)]);

    $this->artisan('yak:recover-stopped-tasks')->assertSuccessful();

    expect($recent->fresh()->auto_recovery_count)->toBe(1)->and($old->fresh()->auto_recovery_count)->toBe(0);

    config()->set('yak.self_recovery.window_hours', 48);
    Queue::fake();

    $this->artisan('yak:recover-stopped-tasks')->assertSuccessful();

    expect($old->fresh()->auto_recovery_count)->toBe(1);
});

it('skips the sweep while a deploy is draining', function () {
    fakeRecoveryGitHub('head111', [['status' => 'completed', 'conclusion' => 'success']]);
    $task = yakStoppedTask();
    Cache::put(PausesDuringDrain::CACHE_KEY, true, now()->addMinutes(5));

    $this->artisan('yak:recover-stopped-tasks')->assertSuccessful();

    Queue::assertNothingPushed();
    Http::assertNothingSent();
    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
});

it('runs every fifteen minutes without overlapping', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains($event->command, 'yak:recover-stopped-tasks'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/15 * * * *')
        ->and($event->withoutOverlapping)->toBeTrue();
});
