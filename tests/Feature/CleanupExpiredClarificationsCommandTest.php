<?php

use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\SendNotificationJob;
use App\Models\YakTask;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

test('expires tasks past their clarification_expires_at', function () {
    $task = YakTask::factory()->awaitingClarification()->create([
        'clarification_expires_at' => now()->subDay(),
    ]);

    $this->artisan('yak:cleanup')->assertSuccessful();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Expired);
    expect($task->completed_at)->not->toBeNull();

    Queue::assertPushed(SendNotificationJob::class, function (SendNotificationJob $job) use ($task) {
        return $job->task->id === $task->id
            && $job->type === NotificationType::Expiry
            && str_contains($job->message, 'Closing this');
    });
});

test('leaves non-expired tasks alone', function () {
    $task = YakTask::factory()->awaitingClarification()->create([
        'clarification_expires_at' => now()->addDay(),
    ]);

    $this->artisan('yak:cleanup')->assertSuccessful();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::AwaitingClarification);

    Queue::assertNotPushed(SendNotificationJob::class);
});

test('leaves non-slack tasks alone', function () {
    $task = YakTask::factory()->create([
        'status' => TaskStatus::Running,
        'source' => 'linear',
        'started_at' => now(),
    ]);

    $this->artisan('yak:cleanup')->assertSuccessful();

    $task->refresh();
    expect($task->status)->toBe(TaskStatus::Running);

    Queue::assertNotPushed(SendNotificationJob::class);
});

test('cleanup command is scheduled daily', function () {
    $schedule = app(Schedule::class);

    $events = collect($schedule->events())->filter(function ($event) {
        return str_contains($event->command ?? '', 'yak:cleanup');
    });

    expect($events)->toHaveCount(1);
    expect($events->first()->expression)->toBe('0 0 * * *');
});
