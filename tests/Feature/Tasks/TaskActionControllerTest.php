<?php

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Jobs\RenderVideoJob;
use App\Jobs\ResearchYakJob;
use App\Jobs\RunYakJob;
use App\Jobs\RunYakReviewJob;
use App\Models\Artifact;
use App\Models\Repository;
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

    $task = YakTask::factory()->create(['mode' => TaskMode::Fix, 'repo' => 'web', 'pr_url' => null]);

    $this->post(route('tasks.reroute', $task), ['repo' => 'api'])
        ->assertRedirect(route('tasks.show', $task));

    Queue::assertPushed(RunYakJob::class);
    expect($task->fresh()->repo)->toBe('api');
    expect($task->fresh()->status)->toBe(TaskStatus::Pending);
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
