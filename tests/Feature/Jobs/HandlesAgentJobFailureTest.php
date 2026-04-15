<?php

use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\SendNotificationJob;
use App\Jobs\SetupYakJob;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\IncusSandboxManager;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeSandboxManager;

beforeEach(function () {
    $this->fakeSandbox = new FakeSandboxManager;
    $this->app->instance(IncusSandboxManager::class, $this->fakeSandbox);
});

it('marks task as failed and records the error when a job times out', function () {
    Repository::factory()->create(['slug' => 'acme/widgets']);
    $task = YakTask::factory()->create([
        'repo' => 'acme/widgets',
        'status' => TaskStatus::Running,
    ]);

    $job = new SetupYakJob($task);
    $job->failed(new RuntimeException('Job has been attempted too many times or run too long'));

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Failed)
        ->and($task->error_log)->toBe('Job has been attempted too many times or run too long')
        ->and($task->completed_at)->not->toBeNull();
});

it('reaps the sandbox container if one still exists when the job fails', function () {
    Repository::factory()->create(['slug' => 'acme/widgets']);
    $task = YakTask::factory()->create([
        'repo' => 'acme/widgets',
        'status' => TaskStatus::Running,
    ]);

    // Simulate the sandbox having been created by a prior step in handle()
    $containerName = $this->fakeSandbox->containerName($task);
    $this->fakeSandbox->createdContainers[] = $containerName;

    $job = new SetupYakJob($task);
    $job->failed(new RuntimeException('timeout'));

    expect($this->fakeSandbox->destroyedContainers)->toContain($containerName);
});

it('dispatches a failure notification for non-system tasks', function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'acme/widgets']);
    $task = YakTask::factory()->create([
        'repo' => 'acme/widgets',
        'status' => TaskStatus::Running,
        'source' => 'slack',
    ]);

    $job = new SetupYakJob($task);
    $job->failed(new RuntimeException('boom'));

    Queue::assertPushed(SendNotificationJob::class, function (SendNotificationJob $dispatched) use ($task) {
        return $dispatched->task->id === $task->id
            && $dispatched->type === NotificationType::Error
            && str_contains($dispatched->message, 'boom');
    });
});

it('does not dispatch a failure notification for system-source tasks', function () {
    Queue::fake();
    Repository::factory()->create(['slug' => 'acme/widgets']);
    $task = YakTask::factory()->create([
        'repo' => 'acme/widgets',
        'status' => TaskStatus::Running,
        'source' => 'system',
    ]);

    $job = new SetupYakJob($task);
    $job->failed(new RuntimeException('boom'));

    Queue::assertNotPushed(SendNotificationJob::class);
});

it('does not clobber a task that is already in a terminal state', function () {
    Repository::factory()->create(['slug' => 'acme/widgets']);
    $task = YakTask::factory()->create([
        'repo' => 'acme/widgets',
        'status' => TaskStatus::Success,
        'completed_at' => now()->subMinute(),
    ]);

    $originalCompletedAt = $task->completed_at;

    $job = new SetupYakJob($task);
    $job->failed(new RuntimeException('late failure after success'));

    $task->refresh();

    expect($task->status)->toBe(TaskStatus::Success)
        ->and($task->error_log)->toBeNull()
        ->and($task->completed_at->equalTo($originalCompletedAt))->toBeTrue();
});
