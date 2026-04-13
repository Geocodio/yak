<?php

use App\Enums\TaskStatus;
use App\Jobs\ProcessCIResultJob;
use App\Jobs\SendNotificationJob;
use App\Models\TaskLog;
use App\Models\YakTask;
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

    // Simulate a CI log entry so ciNeverReported() returns false
    TaskLog::factory()->create([
        'yak_task_id' => $stuck->id,
        'message' => 'CI check_suite started',
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
