<?php

use App\Enums\NotificationType;
use App\Jobs\SendNotificationJob;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Services\Push\PushEvent;
use App\Services\Push\PushRecipients;
use App\Services\Push\ThreadPush;
use App\Services\Threads\GuardStopNotifier;
use App\Services\Threads\RunawayGuard;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Exceptions;

it('pushes to the driver and dispatches no job', function () {
    Bus::fake();
    $driver = User::factory()->create();
    $thread = Thread::factory()->create();
    $run = TaskRun::factory()->create(['thread_id' => $thread->id, 'driver_user_id' => $driver->id]);
    $push = Mockery::mock(ThreadPush::class);
    $push->shouldReceive('send')->once()->withArgs(fn ($event, $given, $recipients, $body, $tag) => $event === PushEvent::NeedsYou
        && $given->is($thread)
        && collect($recipients)->pluck('id')->all() === [$driver->id]
        && str_contains($body, route('threads.show', $thread))
        && $tag === "guard-run-{$run->id}");

    (new GuardStopNotifier(app(RunawayGuard::class), $push, app(PushRecipients::class)))->ask($run, RunawayGuard::ASSISTANT_TURNS, 40);

    Bus::assertNothingDispatched();
});

it('dispatches the task notification when no one drives', function () {
    Bus::fake();
    $run = TaskRun::factory()->create(['driver_user_id' => null]);

    app(GuardStopNotifier::class)->ask($run, RunawayGuard::ASSISTANT_TURNS, 40);

    Bus::assertDispatched(SendNotificationJob::class, fn ($job) => $job->type === NotificationType::Error && ! $job->personalize);
});

it('sends a private thread no title or content', function () {
    $thread = Thread::factory()->private()->create(['title' => 'Secret plan']);
    $run = TaskRun::factory()->create(['thread_id' => $thread->id, 'driver_user_id' => $thread->owner_user_id]);
    $push = Mockery::mock(ThreadPush::class);
    $push->shouldReceive('send')->once()->withArgs(fn ($event, $t, $r, $body) => $body === 'A turn was stopped and needs you. Open Yak to continue.');

    (new GuardStopNotifier(app(RunawayGuard::class), $push, app(PushRecipients::class)))->ask($run, RunawayGuard::ASSISTANT_TURNS, 40);
});

it('swallows and reports a failure', function () {
    Exceptions::fake();
    $run = TaskRun::factory()->create(['thread_id' => Thread::factory()->create()->id, 'driver_user_id' => User::factory()->create()->id]);
    $push = Mockery::mock(ThreadPush::class);
    $push->shouldReceive('send')->andThrow(new RuntimeException('boom'));

    (new GuardStopNotifier(app(RunawayGuard::class), $push, app(PushRecipients::class)))->ask($run, RunawayGuard::ASSISTANT_TURNS, 40);

    Exceptions::assertReported(RuntimeException::class);
});
