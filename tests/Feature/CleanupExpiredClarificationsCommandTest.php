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
            && str_contains($job->message, 'Clarification expired');
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

test('cleanup command is scheduled hourly', function () {
    $schedule = app(Schedule::class);

    $events = collect($schedule->events())->filter(function ($event) {
        // Match "yak:cleanup" at a word boundary so we don't also catch
        // "yak:cleanup-sandboxes" or other yak:cleanup-prefixed commands.
        return preg_match('/\syak:cleanup($|\s)/', (string) $event->command) === 1;
    });

    expect($events)->toHaveCount(1);
    expect($events->first()->expression)->toBe('0 * * * *');
});

test('sends one reminder for a question open past its reminder time and clears it', function () {
    $task = YakTask::factory()->awaitingClarification()->create([
        'clarification_reminder_at' => now()->subMinute(),
        'clarification_expires_at' => now()->addDays(2),
    ]);

    $this->artisan('yak:cleanup')->assertSuccessful();
    $this->artisan('yak:cleanup')->assertSuccessful();

    expect($task->fresh()->clarification_reminder_at)->toBeNull()
        ->and($task->fresh()->status)->toBe(TaskStatus::AwaitingClarification);
    Queue::assertPushed(SendNotificationJob::class, 1);
    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->task->is($task)
        && $job->type === NotificationType::Reminder);
});

test('sends no reminder once the question is answered', function () {
    YakTask::factory()->awaitingClarification()->create([
        'status' => TaskStatus::Running,
        'clarification_reminder_at' => now()->subMinute(),
    ]);

    $this->artisan('yak:cleanup')->assertSuccessful();

    Queue::assertNotPushed(SendNotificationJob::class);
});

test('sends no reminder before it is due', function () {
    YakTask::factory()->awaitingClarification()->create([
        'clarification_reminder_at' => now()->addHour(),
    ]);

    $this->artisan('yak:cleanup')->assertSuccessful();

    Queue::assertNotPushed(SendNotificationJob::class);
});

test('an expiring question is expired, not reminded', function () {
    $task = YakTask::factory()->awaitingClarification()->create([
        'clarification_reminder_at' => now()->subMinute(),
        'clarification_expires_at' => now()->subMinute(),
    ]);

    $this->artisan('yak:cleanup')->assertSuccessful();

    expect($task->fresh()->status)->toBe(TaskStatus::Expired);
    Queue::assertNotPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->type === NotificationType::Reminder);
});

test('a reminder repeats the answer options', function () {
    $task = YakTask::factory()->awaitingClarification()->create([
        'clarification_options' => ['Use the default queue', 'Use the high queue'],
        'clarification_reminder_at' => now()->subMinute(),
        'clarification_expires_at' => now()->addDays(2),
    ]);

    $this->artisan('yak:cleanup')->assertSuccessful();

    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->task->is($task)
        && str_contains($job->message, "1. Use the default queue\n2. Use the high queue"));
});

test('a reminder for structured questions points to the form', function () {
    $task = YakTask::factory()->withClarificationQuestions()->create([
        'clarification_reminder_at' => now()->subMinute(),
        'clarification_expires_at' => now()->addDays(2),
    ]);

    $this->artisan('yak:cleanup')->assertSuccessful();

    Queue::assertPushed(SendNotificationJob::class, fn (SendNotificationJob $job): bool => $job->task->is($task)
        && $job->type === NotificationType::Reminder
        && str_contains($job->message, '#questions'));
});
