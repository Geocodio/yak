<?php

use App\Enums\CoOwnerRequestStatus;
use App\Enums\TaskStatus;
use App\Jobs\SendCoOwnerMessagesJob;
use App\Models\CoOwnerRequest;
use App\Models\YakTask;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Bus;

beforeEach(function () {
    Bus::fake([SendCoOwnerMessagesJob::class]);
});

it('reminds once after two days', function () {
    $request = CoOwnerRequest::factory()->create(['held_at' => now()->subDays(3), 'expires_at' => now()->addDays(4)]);

    $this->artisan('yak:co-owner:sweep')->assertSuccessful();
    $this->artisan('yak:co-owner:sweep')->assertSuccessful();

    expect($request->fresh()->reminded_at)->not->toBeNull();
    Bus::assertDispatchedTimes(SendCoOwnerMessagesJob::class, 1);
    Bus::assertDispatched(SendCoOwnerMessagesJob::class, fn ($job) => $job->event === 'reminder');
});

it('does not remind a request younger than two days', function () {
    CoOwnerRequest::factory()->create(['held_at' => now()->subDay()]);

    $this->artisan('yak:co-owner:sweep')->assertSuccessful();

    Bus::assertNotDispatched(SendCoOwnerMessagesJob::class);
});

it('does not remind a decided request', function () {
    CoOwnerRequest::factory()->create(['status' => CoOwnerRequestStatus::Approved, 'held_at' => now()->subDays(4)]);

    $this->artisan('yak:co-owner:sweep')->assertSuccessful();

    Bus::assertNotDispatched(SendCoOwnerMessagesJob::class);
});

it('expires after seven days and keeps the branch', function () {
    $request = CoOwnerRequest::factory()->create(['held_at' => now()->subDays(8), 'expires_at' => now()->subHour()]);

    $this->artisan('yak:co-owner:sweep')->assertSuccessful();

    expect($request->fresh()->status)->toBe(CoOwnerRequestStatus::Expired)
        ->and($request->task->fresh()->status)->toBe(TaskStatus::Expired)
        ->and($request->task->fresh()->branch_name)->not->toBeNull()
        ->and($request->fresh()->reminded_at)->toBeNull();
    Bus::assertDispatched(SendCoOwnerMessagesJob::class, fn ($job) => $job->event === 'resolved');
    Bus::assertNotDispatched(SendCoOwnerMessagesJob::class, fn ($job) => $job->event === 'reminder');
});

it('is scheduled hourly', function () {
    $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains((string) $event->command, 'yak:co-owner:sweep'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue();
});

it('ignores a pending request whose task is no longer awaiting a co-owner', function () {
    $request = CoOwnerRequest::factory()->create(['held_at' => now()->subDays(8), 'expires_at' => now()->subDay()]);
    YakTask::whereKey($request->task_id)->update(['status' => TaskStatus::AwaitingCi]);

    $this->artisan('yak:co-owner:sweep')->assertSuccessful();

    expect($request->fresh()->status)->toBe(CoOwnerRequestStatus::Pending)->and($request->fresh()->reminded_at)->toBeNull();
    Bus::assertNotDispatched(SendCoOwnerMessagesJob::class);
});
