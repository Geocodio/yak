<?php

use App\Enums\TaskStatus;
use App\Jobs\SendNotificationJob;
use App\Models\YakTask;
use Illuminate\Support\Facades\Queue;

it('fails tasks stuck in awaiting_ci past the timeout', function () {
    Queue::fake();

    $stuck = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingCi,
        'updated_at' => now()->subMinutes(45),
    ]);

    $recent = YakTask::factory()->create([
        'status' => TaskStatus::AwaitingCi,
        'updated_at' => now()->subMinutes(5),
    ]);

    $this->artisan('yak:timeout-ci')->assertSuccessful();

    $stuck->refresh();
    $recent->refresh();

    expect($stuck->status)->toBe(TaskStatus::Failed)
        ->and($stuck->error_log)->toContain('CI timed out')
        ->and($stuck->completed_at)->not->toBeNull();

    expect($recent->status)->toBe(TaskStatus::AwaitingCi);

    Queue::assertPushed(SendNotificationJob::class, 1);
});

it('does nothing when no tasks are stuck', function () {
    Queue::fake();

    $this->artisan('yak:timeout-ci')->assertSuccessful();

    Queue::assertNothingPushed();
});
