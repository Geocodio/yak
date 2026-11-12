<?php

use App\DataTransferObjects\StreamEntry;
use App\DataTransferObjects\TurnTrackerChange;
use App\Enums\NotificationType;
use App\Enums\TurnState;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Jobs\SendNotificationJob;
use App\Models\TaskLog;
use App\Models\User;
use App\Services\Threads\Contracts\BrokerStreams;
use App\Services\Threads\GuardStopNotifier;
use App\Services\Threads\RunawayGuard;
use App\Services\Threads\ThreadDriver;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;

require_once __DIR__ . '/../../../Support/ThreadTurnHelpers.php';

it('stops a runaway turn', function () {
    Event::fake([TurnFailed::class]);
    [$thread, $run] = runningTurnFor();

    $this->travel(241)->minutes();
    app(ThreadDriver::class)->tick();

    expect($run->refresh()->turn_state)->toBe(TurnState::StoppedByGuard)
        ->and(TaskLog::query()->where('message', 'turn_stopped_by_guard')->exists())->toBeTrue()
        ->and(collect(app(BrokerStreams::class)->commands('threads:' . $thread->id))->pluck('type')->all())->toContain('interrupt');
    Event::assertDispatchedTimes(TurnFailed::class, 1);
});

it('ends a turn when the grace window closes', function () {
    Event::fake([TurnCompleted::class]);
    bindTrackerChanges([new TurnTrackerChange], fn (): TurnTrackerChange => now()->diffInSeconds($this->startedAt, true) > 30 ? new TurnTrackerChange(ended: true) : new TurnTrackerChange);
    $this->startedAt = now();
    [$thread, $run] = runningTurnFor();

    app(ThreadDriver::class)->tick();
    expect($run->refresh()->turn_state)->toBe(TurnState::Running);

    $this->travel(31)->seconds();
    app(ThreadDriver::class)->tick();

    expect($run->refresh()->turn_state)->toBe(TurnState::Completed);
    Event::assertDispatchedTimes(TurnCompleted::class, 1);
});

it('lets one failing tick step not stop the others', function () {
    [, $first] = runningTurnFor();
    [, $second] = runningTurnFor();
    $guard = Mockery::mock(RunawayGuard::class);
    $guard->shouldReceive('check')->andReturnUsing(function ($run) use ($first): ?string {
        if ($run->id === $first->id) {
            throw new RuntimeException('boom');
        }

        return 'wall_clock';
    });
    app()->instance(RunawayGuard::class, $guard);

    app(ThreadDriver::class)->tick();

    expect($first->refresh()->turn_state)->toBe(TurnState::Running)
        ->and($second->refresh()->turn_state)->toBe(TurnState::StoppedByGuard);
});

it('fails only the turn of the entry that keeps failing', function () {
    [$threadOne, $runOne] = runningTurnFor();
    [, $runTwo] = runningTurnFor();

    app(ThreadDriver::class)->failTurnOf(new StreamEntry('threads:' . $threadOne->id, '1-0', 1, 1, ['type' => 'bad']));

    expect($runOne->refresh()->turn_state)->toBe(TurnState::Failed)
        ->and($runTwo->refresh()->turn_state)->toBe(TurnState::Running);
});

it('asks the driver to continue after a guard stop', function () {
    $driver = User::factory()->create();
    [, $run] = runningTurnFor(['driver_user_id' => $driver->id]);
    $notifier = Mockery::mock(GuardStopNotifier::class);
    $notifier->shouldReceive('ask')->once()->with(Mockery::on(fn ($given) => $given->id === $run->id), 'wall_clock', Mockery::any());
    app()->instance(GuardStopNotifier::class, $notifier);

    $this->travel(241)->minutes();
    app(ThreadDriver::class)->tick();
});

it('tells the task channel when no one drives', function () {
    Bus::fake([SendNotificationJob::class]);
    [, $run] = runningTurnFor(['driver_user_id' => null]);

    $this->travel(241)->minutes();
    app(ThreadDriver::class)->tick();

    Bus::assertDispatched(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Error && $job->personalize === false);
});

it('leaves the turn stopped when the notifier fails', function () {
    [, $run] = runningTurnFor();
    $notifier = Mockery::mock(GuardStopNotifier::class);
    $notifier->shouldReceive('ask')->andThrow(new RuntimeException('boom'));
    app()->instance(GuardStopNotifier::class, $notifier);

    $this->travel(241)->minutes();
    app(ThreadDriver::class)->tick();

    expect($run->refresh()->turn_state)->toBe(TurnState::StoppedByGuard);
});
