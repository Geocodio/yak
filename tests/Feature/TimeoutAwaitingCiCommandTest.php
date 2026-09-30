<?php

use App\Channels\Drone\PollCommand as DronePollCommand;
use App\Console\Commands\TimeoutAwaitingCiCommand;
use App\Enums\TaskStatus;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\SendNotificationJob;
use App\Models\GitHubInstallationToken;
use App\Models\Repository;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

it('auto-advances to PR creation when CI never reported', function () {
    Queue::fake();

    $stuck = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingCi,
        'attempts' => 1,
        'updated_at' => now()->subMinutes(45),
    ]);

    $this->artisan('yak:timeout-ci')->assertSuccessful();

    Queue::assertPushed(ProcessCIResultJob::class, fn ($job) => $job->task->id === $stuck->id);
    Queue::assertNotPushed(SendNotificationJob::class);
});

it('fails tasks when CI reported but timed out', function () {
    Queue::fake();

    $stuck = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingCi,
        'attempts' => 1,
        'updated_at' => now()->subMinutes(45),
    ]);

    // Simulate a CI result landing so ciNeverReported() returns false
    TaskLog::factory()->create([
        'yak_task_id' => $stuck->id,
        'message' => ProcessCIResultJob::RESULT_LOG_MESSAGE,
    ]);

    $this->artisan('yak:timeout-ci')->assertSuccessful();

    $stuck->refresh();
    expect($stuck->status)->toBe(TaskStatus::Failed)
        ->and($stuck->error_log)->toContain('CI timed out');

    Queue::assertPushed(SendNotificationJob::class, 1);
    Queue::assertNotPushed(ProcessCIResultJob::class);
});

it('does not touch tasks within the timeout window', function () {
    Queue::fake();

    $recent = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingCi,
        'updated_at' => now()->subMinutes(5),
    ]);

    $this->artisan('yak:timeout-ci')->assertSuccessful();

    $recent->refresh();
    expect($recent->status)->toBe(TaskStatus::AwaitingCi);

    Queue::assertNothingPushed();
});

it('does nothing when no tasks are stuck', function () {
    Queue::fake();

    $this->artisan('yak:timeout-ci')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('treats a Drone poll result as CI having reported', function () {
    Queue::fake();

    $stuck = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingCi,
        'attempts' => 1,
        'updated_at' => now()->subMinutes(45),
    ]);

    TaskLog::factory()->create([
        'yak_task_id' => $stuck->id,
        'message' => DronePollCommand::RESULT_LOG_MESSAGE,
    ]);

    $this->artisan('yak:timeout-ci')->assertSuccessful();

    expect($stuck->refresh()->status)->toBe(TaskStatus::Failed);
    Queue::assertNotPushed(ProcessCIResultJob::class);
});

it('does not mistake the agent\'s own log lines for a CI result', function () {
    Queue::fake();

    $stuck = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingCi,
        'attempts' => 1,
        'updated_at' => now()->subMinutes(45),
    ]);

    // Tool-call labels the agent writes while working. None of these mean CI
    // reported anything, but a `LIKE '%CI %'` probe used to match the first
    // one and hard-fail a task that should have advanced to PR creation.
    foreach ([
        '⚡ Run new tests with CI env → exit 0',
        '⚡ Check the CI config file → exit 0',
        'Reviewing check_suite wiring in the workflow',
    ] as $message) {
        TaskLog::factory()->create([
            'yak_task_id' => $stuck->id,
            'message' => $message,
        ]);
    }

    $this->artisan('yak:timeout-ci')->assertSuccessful();

    expect($stuck->refresh()->status)->toBe(TaskStatus::AwaitingCi);
    Queue::assertPushed(ProcessCIResultJob::class, fn ($job) => $job->task->id === $stuck->id);
    Queue::assertNotPushed(SendNotificationJob::class);
});

/*
|--------------------------------------------------------------------------
| Asking GitHub before timing out
|--------------------------------------------------------------------------
*/

function slowGitHubTask(string $ciSystem = 'github_actions', array $overrides = []): YakTask
{
    config()->set('yak.channels.github.installation_id', 99999);
    GitHubInstallationToken::factory()->create([
        'installation_id' => 99999,
        'token' => 'ghs_test_token',
        'expires_at' => now()->addHour(),
    ]);

    Repository::factory()->create(['slug' => 'org/slow', 'github_full_name' => 'org/slow', 'ci_system' => $ciSystem]);

    $task = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingCi,
        'repo' => 'org/slow',
        'branch_name' => 'yak/SLOW',
        'attempts' => 1,
        'updated_at' => now()->subMinutes(45),
        ...$overrides,
    ]);

    TaskLog::factory()->create(['yak_task_id' => $task->id, 'message' => ProcessCIResultJob::RESULT_LOG_MESSAGE]);

    return $task;
}

function fakeWorkflowRuns(array $runs): void
{
    Http::fake(['api.github.com/repos/org/slow/actions/runs*' => Http::response(['workflow_runs' => $runs])]);
}

it('keeps waiting while GitHub reports a queued or running workflow', function (string $status) {
    Queue::fake();
    fakeWorkflowRuns([['id' => 1, 'head_sha' => 'new', 'status' => $status]]);

    $task = slowGitHubTask();

    $this->artisan('yak:timeout-ci')->assertSuccessful();

    expect($task->refresh()->status)->toBe(TaskStatus::AwaitingCi);
    Queue::assertNothingPushed();
})->with(['queued', 'in_progress']);

it('does not skip CI for a never-reported task while a run is queued', function () {
    Queue::fake();
    fakeWorkflowRuns([['id' => 1, 'head_sha' => 'new', 'status' => 'queued']]);

    $task = slowGitHubTask();
    $task->logs()->delete();

    $this->artisan('yak:timeout-ci')->assertSuccessful();

    expect($task->refresh()->status)->toBe(TaskStatus::AwaitingCi);
    Queue::assertNothingPushed();
});

it('logs that CI is still running once per wait, not on every sweep', function () {
    Queue::fake();
    fakeWorkflowRuns([['id' => 1, 'head_sha' => 'new', 'status' => 'queued']]);

    $task = slowGitHubTask();

    $this->artisan('yak:timeout-ci')->assertSuccessful();
    $this->artisan('yak:timeout-ci')->assertSuccessful();

    expect(TaskLog::where('yak_task_id', $task->id)->where('message', 'CI still running on GitHub, waiting')->count())->toBe(1);
});

it('fails as before when the newest commit has only completed runs', function () {
    Queue::fake();
    fakeWorkflowRuns([
        ['id' => 2, 'head_sha' => 'new', 'status' => 'completed'],
        ['id' => 1, 'head_sha' => 'old', 'status' => 'queued'],
    ]);

    $task = slowGitHubTask();

    $this->artisan('yak:timeout-ci')->assertSuccessful();

    expect($task->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toBe('CI timed out after 30 minutes');
});

it('fails as before when GitHub has no run for the branch', function () {
    Queue::fake();
    fakeWorkflowRuns([]);

    $task = slowGitHubTask();

    $this->artisan('yak:timeout-ci')->assertSuccessful();

    expect($task->refresh()->status)->toBe(TaskStatus::Failed);
});

it('fails even with a queued run once the maximum wait has passed', function () {
    Queue::fake();
    fakeWorkflowRuns([['id' => 1, 'head_sha' => 'new', 'status' => 'queued']]);

    $task = slowGitHubTask();
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'agent_finished_at' => now()->subMinutes(200)]);

    $this->artisan('yak:timeout-ci')->assertSuccessful();

    expect($task->refresh()->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toContain('180 minutes')
        ->and($task->error_log)->toStartWith('CI timed out after ');
    Queue::assertPushed(SendNotificationJob::class, 1);
});

it('measures the maximum wait from the last agent push', function () {
    Queue::fake();
    fakeWorkflowRuns([['id' => 1, 'head_sha' => 'new', 'status' => 'queued']]);

    $task = slowGitHubTask();
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'agent_finished_at' => now()->subMinutes(100)]);

    $this->artisan('yak:timeout-ci')->assertSuccessful();

    expect($task->refresh()->status)->toBe(TaskStatus::AwaitingCi);
});

it('fails as before when the GitHub lookup errors', function () {
    Queue::fake();
    Http::fake(['api.github.com/repos/org/slow/actions/runs*' => Http::response(['message' => 'boom'], 500)]);

    $task = slowGitHubTask();

    $this->artisan('yak:timeout-ci')->assertSuccessful();

    expect($task->refresh()->status)->toBe(TaskStatus::Failed);
});

it('does not ask GitHub about Drone repositories', function () {
    Queue::fake();
    Http::fake();

    $task = slowGitHubTask('drone');

    $this->artisan('yak:timeout-ci')->assertSuccessful();

    expect($task->refresh()->status)->toBe(TaskStatus::Failed);
    Http::assertNothingSent();
});

test('the maximum wait restarts when a dashboard retry goes back to CI', function () {
    config()->set('yak.ci_max_wait_minutes', 180);
    $task = slowGitHubTask();
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'agent_finished_at' => now()->subMinutes(200)]);
    TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'message' => TimeoutAwaitingCiCommand::CI_RESUMED_LOG_MESSAGE,
        'created_at' => now()->subMinutes(40),
    ]);
    Http::fake(['api.github.com/repos/org/slow/actions/runs*' => Http::response(['workflow_runs' => [
        ['id' => 1, 'head_sha' => 'abc', 'status' => 'queued', 'conclusion' => null],
    ]])]);

    $this->artisan('yak:timeout-ci');

    expect($task->fresh()->status)->toBe(TaskStatus::AwaitingCi);
});
