<?php

use App\Events\RunnerExited;
use App\Events\ThreadActivity;
use App\Events\TurnCompleted;
use App\Events\TurnFailed;
use App\Events\TurnStarted;
use App\Models\TaskRun;
use App\Models\Thread;
use Illuminate\Support\Facades\Event;

it('constructs every event with the documented properties', function () {
    Event::fake();
    $thread = Thread::factory()->create();
    $run = TaskRun::factory()->create(['thread_id' => $thread->id]);

    TurnStarted::dispatch($run);
    TurnCompleted::dispatch($run);
    TurnFailed::dispatch($run);
    RunnerExited::dispatch($thread, 2, -1);
    ThreadActivity::dispatch($thread, 2, 7, ['type' => 'assistant']);

    Event::assertDispatched(TurnStarted::class, fn ($event) => $event->run->is($run));
    Event::assertDispatched(TurnCompleted::class, fn ($event) => $event->run->is($run));
    Event::assertDispatched(TurnFailed::class, fn ($event) => $event->run->is($run));
    Event::assertDispatched(RunnerExited::class, fn ($event) => $event->thread->is($thread) && $event->generation === 2 && $event->exitCode === -1);
    Event::assertDispatched(ThreadActivity::class, fn ($event) => $event->thread->is($thread) && $event->generation === 2 && $event->sequence === 7 && $event->line === ['type' => 'assistant']);
});
