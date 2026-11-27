<?php

use App\Channels\GitHub\AppService;
use App\Enums\NotificationType;
use App\Enums\StopKind;
use App\Enums\TaskStatus;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\RecordCiJobGreenJob;
use App\Jobs\RetryYakJob;
use App\Jobs\SendNotificationJob;
use App\Models\GitHubInstallationToken;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\CiFailure\CiFailureIntake;
use App\Services\DefaultBranchCi;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    config()->set('yak.channels.github.installation_id', 99999);
    config()->set('yak.max_attempts', 2);
    GitHubInstallationToken::factory()->create([
        'installation_id' => 99999,
        'token' => 'ghs_test_token',
        'expires_at' => now()->addHour(),
    ]);

    $this->repository = Repository::factory()->create([
        'slug' => 'org/widgets',
        'github_full_name' => 'acme/widgets',
        'default_branch' => 'main',
        'ci_system' => 'github_actions',
    ]);

    $this->task = YakTask::factory()->awaitingCi()->create([
        'repo' => 'org/widgets',
        'branch_name' => 'yak/fix',
        'source' => 'manual',
        'attempts' => 1,
        'pr_number' => 7,
        'pr_url' => 'https://github.com/acme/widgets/pull/7',
    ]);
});

/**
 * @param  array<string, string>  $checks  check name => conclusion
 * @return array<string, mixed>
 */
function checkRuns(array $checks): array
{
    return ['check_runs' => array_map(
        fn (string $name, string $conclusion): array => ['name' => $name, 'conclusion' => $conclusion],
        array_keys($checks),
        array_values($checks),
    )];
}

/**
 * @param  array<string, string>  $branchChecks
 * @param  array<string, string>  $defaultChecks
 * @param  array<string, mixed>  $extra  more URL patterns
 */
function fakeGitHubChecks(array $branchChecks, array $defaultChecks, int $behindBy = 0, array $extra = []): void
{
    Http::fake($extra + [
        '*commits/yak/fix/check-runs*' => Http::response(checkRuns($branchChecks)),
        '*commits/main/check-runs*' => Http::response(checkRuns($defaultChecks)),
        '*compare/main...*' => Http::response(['behind_by' => $behindBy]),
        '*branches/main' => Http::response(['commit' => ['sha' => 'default-sha-1']]),
        '*pulls/7/update-branch' => Http::response(['message' => 'Updating pull request branch.'], 202),
        '*merges' => Http::response(['sha' => 'merge-sha'], 201),
    ]);
}

function sentTo(string $needle): bool
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), $needle))->isNotEmpty();
}

test('a failure the default branch shares parks the task without a retry and says so', function () {
    Queue::fake();
    fakeGitHubChecks(['Security Audit' => 'failure'], ['Security Audit' => 'failure']);

    (new ProcessCIResultJob($this->task, false, '## Security Audit'))->handle();

    $task = $this->task->fresh();
    expect($task->status)->toBe(TaskStatus::AwaitingCi)
        ->and($task->attempts)->toBe(1)
        ->and($task->waiting_on_default_branch_at)->not->toBeNull()
        ->and($task->default_branch_wait_checks)->toBe(['Security Audit']);

    Queue::assertNotPushed(RetryYakJob::class);
    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Progress
        && str_contains($job->message, 'Security Audit')
        && str_contains($job->message, 'main'));
});

test('a parked task is not failed by yak:timeout-ci', function () {
    Queue::fake();
    $this->task->update([
        'waiting_on_default_branch_at' => now()->subHours(5),
        'default_branch_wait_checks' => ['Security Audit'],
        'updated_at' => now()->subHours(5),
    ]);

    $this->artisan('yak:timeout-ci')->assertSuccessful();

    expect($this->task->fresh()->status)->toBe(TaskStatus::AwaitingCi);
    Queue::assertNothingPushed();
});

test('a task parked past the wait limit is failed with a clear stop', function () {
    Queue::fake();
    $this->task->update([
        'waiting_on_default_branch_at' => now()->subHours(25),
        'default_branch_wait_checks' => ['Security Audit'],
    ]);

    $this->artisan('yak:timeout-ci')->assertSuccessful();

    $task = $this->task->fresh();
    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->stop_kind)->toBe(StopKind::YakStopped)
        ->and($task->stop_summary)->toContain('default branch kept failing the same checks')
        ->and($task->stop_summary)->toContain('Security Audit');
});

test('a default branch check going green updates a parked pull request branch and waits for CI', function () {
    Queue::fake();
    $this->task->update([
        'waiting_on_default_branch_at' => now()->subHour(),
        'default_branch_wait_checks' => ['Security Audit'],
    ]);
    fakeGitHubChecks([], ['Security Audit' => 'success'], extra: [
        '*actions/runs/55' => Http::response(['head_repository' => ['full_name' => 'acme/widgets'], 'event' => 'push', 'head_sha' => 'default-sha-1']),
    ]);

    (new RecordCiJobGreenJob($this->repository->id, [
        'workflow_job' => ['run_id' => 55, 'head_sha' => 'default-sha-1', 'head_branch' => 'main', 'name' => 'Security Audit', 'workflow_name' => 'CI', 'completed_at' => now()->toIso8601String()],
    ]))->handle(app(AppService::class), app(CiFailureIntake::class), app(DefaultBranchCi::class));

    $task = $this->task->fresh();
    expect($task->status)->toBe(TaskStatus::AwaitingCi)
        ->and($task->waiting_on_default_branch_at)->toBeNull()
        ->and($task->default_branch_wait_checks)->toBeNull()
        ->and($task->attempts)->toBe(1)
        ->and(sentTo('pulls/7/update-branch'))->toBeTrue();
    expect($task->logs()->where('message', DefaultBranchCi::RESYNCED_LOG_MESSAGE)->exists())->toBeTrue();
});

test('a parked task without a pull request is updated through a merge', function () {
    Queue::fake();
    $this->task->update([
        'pr_number' => null,
        'pr_url' => null,
        'waiting_on_default_branch_at' => now(),
        'default_branch_wait_checks' => ['Security Audit'],
    ]);
    fakeGitHubChecks([], ['Security Audit' => 'success']);

    app(DefaultBranchCi::class)->unparkReadyTasks($this->repository);

    expect(sentTo('/merges'))->toBeTrue()
        ->and($this->task->fresh()->waiting_on_default_branch_at)->toBeNull();
});

test('a parked task stays parked while the default branch still fails its checks', function () {
    Queue::fake();
    $this->task->update(['waiting_on_default_branch_at' => now(), 'default_branch_wait_checks' => ['Security Audit']]);
    fakeGitHubChecks([], ['Security Audit' => 'failure']);

    app(DefaultBranchCi::class)->unparkReadyTasks($this->repository);

    expect($this->task->fresh()->waiting_on_default_branch_at)->not->toBeNull()
        ->and(sentTo('update-branch'))->toBeFalse();
});

test('a merge conflict while unparking sends the task through the normal retry', function () {
    Queue::fake();
    $this->task->update(['waiting_on_default_branch_at' => now(), 'default_branch_wait_checks' => ['Security Audit']]);
    fakeGitHubChecks([], ['Security Audit' => 'success'], extra: [
        '*pulls/7/update-branch' => Http::response(['message' => 'merge conflict'], 422),
    ]);

    app(DefaultBranchCi::class)->unparkReadyTasks($this->repository);

    expect($this->task->fresh()->waiting_on_default_branch_at)->toBeNull();
    Queue::assertPushed(ProcessCIResultJob::class, fn (ProcessCIResultJob $job): bool => $job->passed === false);
});

test('a behind branch whose failures pass on the default branch is synced once instead of retried', function () {
    Queue::fake();
    fakeGitHubChecks(['Tests' => 'failure'], ['Tests' => 'success'], behindBy: 3);

    (new ProcessCIResultJob($this->task, false, '## Tests'))->handle();

    $task = $this->task->fresh();
    expect($task->status)->toBe(TaskStatus::AwaitingCi)
        ->and($task->attempts)->toBe(1)
        ->and($task->default_branch_synced_sha)->toBe('default-sha-1')
        ->and(sentTo('pulls/7/update-branch'))->toBeTrue();
    Queue::assertNotPushed(RetryYakJob::class);
});

test('a second red on the same default branch head retries normally', function () {
    Queue::fake();
    $this->task->update(['default_branch_synced_sha' => 'default-sha-1']);
    fakeGitHubChecks(['Tests' => 'failure'], ['Tests' => 'success'], behindBy: 3);

    (new ProcessCIResultJob($this->task, false, '## Tests'))->handle();

    expect($this->task->fresh()->status)->toBe(TaskStatus::Retrying)
        ->and($this->task->fresh()->attempts)->toBe(2)
        ->and(sentTo('update-branch'))->toBeFalse();
    Queue::assertPushed(RetryYakJob::class);
});

test('a branch that is not behind retries without a sync', function () {
    Queue::fake();
    fakeGitHubChecks(['Tests' => 'failure'], ['Tests' => 'success'], behindBy: 0);

    (new ProcessCIResultJob($this->task, false, '## Tests'))->handle();

    expect($this->task->fresh()->status)->toBe(TaskStatus::Retrying);
    expect(sentTo('update-branch'))->toBeFalse();
});

test('a merge conflict during the sync falls through to the retry', function () {
    Queue::fake();
    fakeGitHubChecks(['Tests' => 'failure'], ['Tests' => 'success'], behindBy: 2, extra: [
        '*pulls/7/update-branch' => Http::response(['message' => 'merge conflict'], 422),
    ]);

    (new ProcessCIResultJob($this->task, false, '## Tests'))->handle();

    expect($this->task->fresh()->status)->toBe(TaskStatus::Retrying);
    Queue::assertPushed(RetryYakJob::class);
});

test('a failure partly shared with the default branch retries as today', function () {
    Queue::fake();
    fakeGitHubChecks(['Tests' => 'failure', 'Security Audit' => 'failure'], ['Security Audit' => 'failure'], behindBy: 2);

    (new ProcessCIResultJob($this->task, false, '## Tests'))->handle();

    expect($this->task->fresh()->status)->toBe(TaskStatus::Retrying)
        ->and($this->task->fresh()->waiting_on_default_branch_at)->toBeNull();
    expect(sentTo('update-branch'))->toBeFalse();
});

test('GitHub read errors fall through to the normal retry', function () {
    Queue::fake();
    Http::fake(['*' => Http::response(['message' => 'boom'], 500)]);

    (new ProcessCIResultJob($this->task, false, '## Tests'))->handle();

    expect($this->task->fresh()->status)->toBe(TaskStatus::Retrying)
        ->and($this->task->fresh()->waiting_on_default_branch_at)->toBeNull();
    Queue::assertPushed(RetryYakJob::class);
});

test('a shared failure parks the task even with no retries left', function () {
    Queue::fake();
    $this->task->update(['attempts' => 2]);
    fakeGitHubChecks(['Security Audit' => 'failure'], ['Security Audit' => 'failure']);

    (new ProcessCIResultJob($this->task, false, '## Security Audit'))->handle();

    expect($this->task->fresh()->status)->toBe(TaskStatus::AwaitingCi)
        ->and($this->task->fresh()->waiting_on_default_branch_at)->not->toBeNull();
});

test('only one caller claims a default branch head when green jobs race', function () {
    fakeGitHubChecks([], ['Security Audit' => 'success']);
    $first = YakTask::query()->findOrFail($this->task->id);
    $stale = YakTask::query()->findOrFail($this->task->id);

    $defaultBranchCi = app(DefaultBranchCi::class);

    expect($defaultBranchCi->claimDefaultBranchHead($first, $this->repository))->not->toBeNull()
        ->and($defaultBranchCi->claimDefaultBranchHead($stale, $this->repository))->toBeNull();
});
