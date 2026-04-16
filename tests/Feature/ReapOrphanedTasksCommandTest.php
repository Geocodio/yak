<?php

use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\SendNotificationJob;
use App\Models\TaskLog;
use App\Models\YakTask;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Process::fake(['*' => Process::result(exitCode: 1)]); // no container by default
});

test('reaps a Running task with no log activity past the threshold', function () {
    Queue::fake();

    $task = YakTask::factory()->running()->create([
        'source' => 'slack',
        'slack_channel' => 'C1',
        'slack_thread_ts' => '1.1',
        'updated_at' => now()->subMinutes(30),
    ]);

    $this->artisan('yak:reap-orphaned-tasks', ['--minutes' => 15])
        ->assertSuccessful();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Failed);
    expect($task->error_log)->toContain('Worker crashed mid-run');
    expect($task->completed_at)->not->toBeNull();

    Queue::assertPushed(SendNotificationJob::class, fn ($job) => $job->task->id === $task->id && $job->type === NotificationType::Error);
});

test('spares a Running task that had log activity within the threshold', function () {
    Queue::fake();

    $task = YakTask::factory()->running()->create([
        'updated_at' => now()->subMinutes(30),
    ]);

    TaskLog::factory()->create([
        'yak_task_id' => $task->id,
        'created_at' => now()->subMinutes(5),
    ]);

    $this->artisan('yak:reap-orphaned-tasks', ['--minutes' => 15])
        ->assertSuccessful();

    expect($task->fresh()->status)->toBe(TaskStatus::Running);
    Queue::assertNothingPushed();
});

test('spares a task still being updated (updated_at within threshold)', function () {
    Queue::fake();

    $task = YakTask::factory()->running()->create([
        'updated_at' => now()->subMinutes(5),
    ]);

    $this->artisan('yak:reap-orphaned-tasks', ['--minutes' => 15])
        ->assertSuccessful();

    expect($task->fresh()->status)->toBe(TaskStatus::Running);
});

test('ignores non-Running statuses', function () {
    Queue::fake();

    foreach ([TaskStatus::AwaitingCi, TaskStatus::AwaitingClarification, TaskStatus::Retrying, TaskStatus::Pending] as $status) {
        YakTask::factory()->create([
            'status' => $status,
            'updated_at' => now()->subHours(2),
        ]);
    }

    $this->artisan('yak:reap-orphaned-tasks', ['--minutes' => 15])
        ->assertSuccessful();

    // None of them flipped to Failed.
    expect(YakTask::where('status', TaskStatus::Failed)->count())->toBe(0);
});

test('does not notify system-source tasks', function () {
    Queue::fake();

    $task = YakTask::factory()->running()->create([
        'source' => 'system',
        'updated_at' => now()->subMinutes(30),
    ]);

    $this->artisan('yak:reap-orphaned-tasks', ['--minutes' => 15])
        ->assertSuccessful();

    expect($task->fresh()->status)->toBe(TaskStatus::Failed);
    Queue::assertNothingPushed();
});
