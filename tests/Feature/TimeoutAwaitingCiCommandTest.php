<?php

use App\Channels\Drone\PollCommand as DronePollCommand;
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
