<?php

use App\Enums\NotificationType;
use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\RenderVideoJob;
use App\Jobs\ResearchYakJob;
use App\Jobs\RetryYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\RunYakReviewJob;
use App\Jobs\SendNotificationJob;
use App\Models\Artifact;
use App\Models\GitHubInstallationToken;
use App\Models\LinearOauthConnection;
use App\Models\PendingSteeringMessage;
use App\Models\Repository;
use App\Models\TaskLog;
use App\Models\User;
use App\Models\YakTask;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('retry re-queues a failed task and dispatches RunYakJob', function () {
    Queue::fake();
    $task = YakTask::factory()->create(['status' => TaskStatus::Failed, 'error_log' => 'boom']);

    $this->post(route('tasks.retry', $task))
        ->assertRedirect(route('tasks.show', $task));

    Queue::assertPushed(RunYakJob::class);
    expect($task->fresh()->status)->toBe(TaskStatus::Pending);
    expect($task->fresh()->error_log)->toBeNull();
});

test('retry folds thread replies queued during the failed run into the fresh run', function () {
    Queue::fake();
    $task = YakTask::factory()->create(['status' => TaskStatus::Failed, 'description' => 'Update Emily\'s title']);
    PendingSteeringMessage::create(['root_task_id' => $task->id, 'text' => 'Update Cory\'s title too', 'source' => 'slack']);

    $this->post(route('tasks.retry', $task));

    expect($task->fresh()->description)->toBe("Update Emily's title\n\nReplies added in the thread since this request:\n\n- Update Cory's title too");
    expect(PendingSteeringMessage::count())->toBe(0);
    Queue::assertPushed(RunYakJob::class);
});

test('retry continues a task that failed CI on its existing branch', function () {
    Queue::fake();
    $task = YakTask::factory()->create([
        'status' => TaskStatus::Failed,
        'mode' => TaskMode::Fix,
        'branch_name' => 'yak/SLACK-1',
        'attempts' => 2,
        'result_summary' => 'Bumped laravel/framework',
        'error_log' => '## Test (Browser) (https://github.com/org/repo/runs/1)',
    ]);
    TaskLog::factory()->create(['yak_task_id' => $task->id, 'message' => ProcessCIResultJob::RESULT_LOG_MESSAGE]);

    $this->post(route('tasks.retry', $task))
        ->assertRedirect(route('tasks.show', $task));

    Queue::assertNotPushed(RunYakJob::class);
    Queue::assertPushed(RetryYakJob::class, fn (RetryYakJob $job) => $job->task->is($task)
        && $job->failureOutput === '## Test (Browser) (https://github.com/org/repo/runs/1)');

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Retrying)
        ->and($task->attempts)->toBe(3)
        ->and($task->attempts_at_manual_retry)->toBe(2)
        ->and($task->hasCiRetryLeft())->toBeTrue()
        ->and($task->branch_name)->toBe('yak/SLACK-1')
        ->and($task->result_summary)->toBe('Bumped laravel/framework')
        ->and($task->error_log)->toBeNull();
});

test('retry starts over when CI never reported on the branch', function () {
    Queue::fake();
    $task = YakTask::factory()->create([
        'status' => TaskStatus::Failed,
        'mode' => TaskMode::Fix,
        'branch_name' => 'yak/SLACK-2',
        'attempts' => 2,
    ]);

    $this->post(route('tasks.retry', $task));

    Queue::assertPushed(RunYakJob::class);
    Queue::assertNotPushed(RetryYakJob::class);
    expect($task->fresh()->attempts_at_manual_retry)->toBe(2);
});

test('retry clears a stale pr body update and review replies', function () {
    Queue::fake();
    $task = YakTask::factory()->create([
        'status' => TaskStatus::Failed,
        'pr_body_update' => 'stale description',
        'review_replies' => ['abc' => 'stale reply'],
    ]);

    $this->post(route('tasks.retry', $task));

    expect($task->fresh()->pr_body_update)->toBeNull();
    expect($task->fresh()->review_replies)->toBeNull();
});

test('retry does nothing for a task that cannot be retried', function () {
    Queue::fake();
    $task = YakTask::factory()->create(['status' => TaskStatus::Running]);

    $this->post(route('tasks.retry', $task))->assertRedirect(route('tasks.show', $task));

    Queue::assertNothingPushed();
    expect($task->fresh()->status)->toBe(TaskStatus::Running);
});

test('retry dispatches ResearchYakJob for research tasks', function () {
    Queue::fake();
    $task = YakTask::factory()->create(['status' => TaskStatus::Failed, 'mode' => TaskMode::Research]);

    $this->post(route('tasks.retry', $task));

    Queue::assertPushed(ResearchYakJob::class);
});

test('retry restamps dispatched_at through AgentJobDispatcher', function () {
    Queue::fake();
    $task = YakTask::factory()->create([
        'status' => TaskStatus::Failed,
        'dispatched_at' => now()->subDays(3),
        'queue_job_uuid' => 'stale-uuid',
    ]);

    $this->post(route('tasks.retry', $task));

    $task->refresh();
    expect($task->dispatched_at)->not->toBeNull()
        ->and($task->dispatched_at->greaterThan(now()->subMinute()))->toBeTrue();
});

test('cancel destroys the sandbox and marks the task cancelled', function () {
    Queue::fake();
    Process::fake(['*' => Process::result(exitCode: 0)]);
    $task = YakTask::factory()->create(['status' => TaskStatus::Running]);

    $this->post(route('tasks.cancel', $task))->assertRedirect(route('tasks.show', $task));

    expect($task->fresh()->status)->toBe(TaskStatus::Cancelled);
    Process::assertRan(fn ($p) => str_contains($p->command, 'incus delete'));
});

test('cancel does nothing for a task that cannot be cancelled', function () {
    $task = YakTask::factory()->create(['status' => TaskStatus::Success]);

    $this->post(route('tasks.cancel', $task));

    expect($task->fresh()->status)->toBe(TaskStatus::Success);
});

test('rerun review dispatches RunYakReviewJob for a review task', function () {
    Queue::fake();

    $keyPair = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($keyPair, $privateKey);
    config()->set('yak.channels.github.app_id', '999');
    config()->set('yak.channels.github.private_key', $privateKey);
    config()->set('yak.channels.github.installation_id', 12345);

    $task = YakTask::factory()->create([
        'mode' => TaskMode::Review,
        'status' => TaskStatus::Success,
        'repo' => 'geocodio/api',
        'context' => json_encode(['pr_number' => 7]),
        'pr_body_update' => 'stale description',
        'review_replies' => ['abc' => 'stale reply'],
    ]);

    Http::fake([
        'api.github.com/app/installations/*/access_tokens' => Http::response([
            'token' => 'tok', 'expires_at' => now()->addHour()->toIso8601String(),
        ]),
        '*' => Http::response([
            'number' => 7,
            'head' => ['sha' => 'abc123', 'ref' => 'feature/x'],
            'base' => ['sha' => 'def456', 'ref' => 'main'],
            'user' => ['login' => 'octocat'],
            'title' => 'A PR',
            'body' => 'Body',
        ]),
    ]);

    $this->post(route('tasks.rerun-review', $task))->assertRedirect(route('tasks.show', $task));

    Queue::assertPushed(RunYakReviewJob::class);
    expect($task->fresh()->status)->toBe(TaskStatus::Pending);
    expect($task->fresh()->pr_body_update)->toBeNull();
    expect($task->fresh()->review_replies)->toBeNull();
});

test('rerun review does nothing for a non-review task', function () {
    Queue::fake();
    $task = YakTask::factory()->create(['mode' => TaskMode::Fix]);

    $this->post(route('tasks.rerun-review', $task));

    Queue::assertNothingPushed();
});

test('retry render dispatches RenderVideoJob when raw footage exists', function () {
    Queue::fake();
    $task = YakTask::factory()->create();
    $raw = Artifact::factory()->for($task, 'task')->create(['role' => 'raw', 'type' => 'video']);

    $this->post(route('tasks.retry-render', $task))->assertRedirect(route('tasks.show', $task));

    Queue::assertPushed(RenderVideoJob::class, fn (RenderVideoJob $job) => true);
});

test('retry render does nothing without raw footage', function () {
    Queue::fake();
    $task = YakTask::factory()->create();

    $this->post(route('tasks.retry-render', $task));

    Queue::assertNothingPushed();
});

test('reroute moves the task to a new repo and restarts it', function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'web', 'is_active' => true]);
    Repository::factory()->create(['slug' => 'api', 'is_active' => true]);

    $task = YakTask::factory()->create([
        'mode' => TaskMode::Fix,
        'repo' => 'web',
        'pr_url' => null,
        'pr_body_update' => 'stale description',
        'review_replies' => ['abc' => 'stale reply'],
    ]);

    $this->post(route('tasks.reroute', $task), ['repo' => 'api'])
        ->assertRedirect(route('tasks.show', $task));

    Queue::assertPushed(RunYakJob::class);
    expect($task->fresh()->repo)->toBe('api');
    expect($task->fresh()->status)->toBe(TaskStatus::Pending);
    expect($task->fresh()->pr_body_update)->toBeNull();
    expect($task->fresh()->review_replies)->toBeNull();
});

test('reroute restamps dispatched_at through AgentJobDispatcher', function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'web', 'is_active' => true]);
    Repository::factory()->create(['slug' => 'api', 'is_active' => true]);

    $task = YakTask::factory()->create([
        'mode' => TaskMode::Fix,
        'repo' => 'web',
        'pr_url' => null,
        'dispatched_at' => now()->subDays(3),
        'queue_job_uuid' => 'stale-uuid',
    ]);

    $this->post(route('tasks.reroute', $task), ['repo' => 'api'])
        ->assertRedirect(route('tasks.show', $task));

    $task->refresh();
    expect($task->dispatched_at)->not->toBeNull()
        ->and($task->dispatched_at->greaterThan(now()->subMinute()))->toBeTrue();
});

test('reroute is rejected for a review task', function () {
    Repository::factory()->create(['slug' => 'other', 'is_active' => true]);
    $task = YakTask::factory()->create(['mode' => TaskMode::Review, 'repo' => 'web']);

    $this->post(route('tasks.reroute', $task), ['repo' => 'other']);

    expect($task->fresh()->repo)->toBe('web');
});

test('reroute validates the target repo exists', function () {
    $task = YakTask::factory()->create(['mode' => TaskMode::Fix, 'repo' => 'web', 'pr_url' => null]);

    $this->post(route('tasks.reroute', $task), ['repo' => 'does-not-exist'])
        ->assertSessionHasErrors(['repo']);
});

function ciTimedOutTask(): YakTask
{
    config()->set('yak.channels.github.installation_id', 99999);
    GitHubInstallationToken::factory()->create([
        'installation_id' => 99999,
        'token' => 'ghs_test_token',
        'expires_at' => now()->addHour(),
    ]);
    Repository::factory()->create(['slug' => 'org/slow', 'github_full_name' => 'org/slow', 'ci_system' => 'github_actions']);

    return YakTask::factory()->create([
        'status' => TaskStatus::Failed,
        'mode' => TaskMode::Fix,
        'repo' => 'org/slow',
        'branch_name' => 'yak/FIX-SLOW',
        'attempts' => 1,
        'error_log' => 'CI timed out after 30 minutes',
        'completed_at' => now(),
    ]);
}

/**
 * @param  list<array{id: int, head_sha: string, status: string, conclusion: ?string}>  $runs
 */
function fakeBranchRuns(array $runs): void
{
    Http::fake([
        'api.github.com/repos/org/slow/actions/runs/*/rerun-failed-jobs' => Http::response([], 201),
        'api.github.com/repos/org/slow/actions/runs*' => Http::response(['workflow_runs' => $runs]),
    ]);
}

test('retry after a CI timeout uses a green result without running the agent again', function () {
    Queue::fake();
    $task = ciTimedOutTask();
    fakeBranchRuns([
        ['id' => 2, 'head_sha' => 'new222', 'status' => 'completed', 'conclusion' => 'success'],
        ['id' => 1, 'head_sha' => 'old111', 'status' => 'completed', 'conclusion' => 'failure'],
    ]);

    $this->post(route('tasks.retry', $task))->assertRedirect(route('tasks.show', $task));

    Queue::assertNotPushed(RunYakJob::class);
    Queue::assertNotPushed(RetryYakJob::class);
    Queue::assertPushed(ProcessCIResultJob::class, fn (ProcessCIResultJob $job) => $job->passed && $job->commitSha === 'new222');
    expect($task->fresh()->status)->toBe(TaskStatus::AwaitingCi)
        ->and($task->fresh()->error_log)->toBeNull();
});

test('retry after a CI timeout keeps waiting while the run is still going', function () {
    Queue::fake();
    $task = ciTimedOutTask();
    fakeBranchRuns([['id' => 2, 'head_sha' => 'new222', 'status' => 'queued', 'conclusion' => null]]);

    $this->post(route('tasks.retry', $task));

    Queue::assertNothingPushed();
    expect($task->fresh()->status)->toBe(TaskStatus::AwaitingCi);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'rerun-failed-jobs'));
});

test('retry after a CI timeout re-runs the failed jobs of a red run', function () {
    Queue::fake();
    $task = ciTimedOutTask();
    fakeBranchRuns([['id' => 2, 'head_sha' => 'new222', 'status' => 'completed', 'conclusion' => 'failure']]);

    $this->post(route('tasks.retry', $task));

    Queue::assertNothingPushed();
    Http::assertSent(fn ($request) => str_ends_with($request->url(), '/actions/runs/2/rerun-failed-jobs'));
    expect($task->fresh()->status)->toBe(TaskStatus::AwaitingCi);
});

test('retry after a CI timeout falls back to the agent when GitHub cannot say', function () {
    Queue::fake();
    $task = ciTimedOutTask();
    Http::fake(['api.github.com/*' => Http::response(['message' => 'boom'], 500)]);

    $this->post(route('tasks.retry', $task));

    Queue::assertPushed(RunYakJob::class);
    expect($task->fresh()->status)->toBe(TaskStatus::Pending);
});

test('cancel sends a Cancelled notice naming who cancelled', function () {
    Queue::fake();
    Process::fake(['*' => Process::result(exitCode: 0)]);
    $canceller = User::factory()->create(['name' => 'Other Person']);
    $this->actingAs($canceller);
    $task = YakTask::factory()->create(['status' => TaskStatus::Running, 'source' => 'dashboard']);

    $this->post(route('tasks.cancel', $task));

    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $notification): bool => $notification->type === NotificationType::Cancelled
        && $notification->actingUser?->is($canceller) === true
        && str_contains($notification->message, 'Other Person'));
});

test('cancel moves a Linear issue to the cancelled state once', function () {
    Process::fake(['*' => Process::result(exitCode: 0)]);
    Http::fake(['api.linear.app/*' => Http::response(['data' => ['success' => true]])]);
    LinearOauthConnection::factory()->create();
    config()->set('yak.channels.linear.webhook_secret', 'linear-secret');
    config()->set('yak.channels.linear.cancelled_state_id', 'cancelled-state');
    $task = YakTask::factory()->create([
        'status' => TaskStatus::Running,
        'source' => 'linear',
        'external_id' => 'LIN-9',
        'linear_agent_session_id' => 'session-cancel',
    ]);

    $this->post(route('tasks.cancel', $task));

    $issueUpdates = Http::recorded()->filter(fn (array $pair): bool => str_contains($pair[0]['query'] ?? '', 'issueUpdate'));
    expect($issueUpdates)->toHaveCount(1);
});
