<?php

use App\Enums\TurnState;
use App\Models\Device;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadBusy;
use App\Services\Threads\ThreadTurns;

function guardStoppedThread(TurnState $state = TurnState::StoppedByGuard): array
{
    $thread = Thread::factory()->create();
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => $state]);

    return [$thread, $task, User::factory()->create()];
}

it('starts a new turn after a guard stop', function () {
    [$thread, $task, $user] = guardStoppedThread();
    $this->mock(ThreadTurns::class)->shouldReceive('start')->once()
        ->withArgs(fn ($t, $prompt, $driver, $model) => $t->is($task) && $prompt === 'Continue.' && $driver->is($user) && $model === null)
        ->andReturn(new TaskRun);

    $this->actingAs($user)->post(route('threads.continue', $thread))->assertSessionHas('success');
});

it('does nothing and flashes an error when the last turn did not stop by the guard', function () {
    [$thread, , $user] = guardStoppedThread(TurnState::Completed);
    $this->mock(ThreadTurns::class)->shouldNotReceive('start');

    $this->actingAs($user)->post(route('threads.continue', $thread))->assertSessionHas('error');
});

it('404s for a hidden private thread', function () {
    $thread = Thread::factory()->private()->create();
    $this->mock(ThreadTurns::class)->shouldNotReceive('start');

    $this->actingAs(User::factory()->create())->post(route('threads.continue', $thread))->assertNotFound();
});

it('refuses a double click while a turn runs', function () {
    [$thread, , $user] = guardStoppedThread();
    $this->mock(ThreadTurns::class)->shouldReceive('start')->once()->andThrow(new ThreadBusy('busy'));

    $this->actingAs($user)->post(route('threads.continue', $thread))->assertSessionHas('error');
});

it('refuses on a closed thread', function () {
    [$thread, , $user] = guardStoppedThread();
    $thread->forceFill(['status' => 'closed', 'closed_at' => now()])->save();
    $this->mock(ThreadTurns::class)->shouldNotReceive('start');

    $this->actingAs($user)->post(route('threads.continue', $thread))->assertSessionHas('error');
});

it('refuses while a computer owns the thread', function () {
    [$thread, , $user] = guardStoppedThread();
    $thread->forceFill(['owner_side' => 'device', 'owner_device_id' => Device::factory()->create()->id])->save();
    $this->mock(ThreadTurns::class)->shouldNotReceive('start');

    $this->actingAs($user)->post(route('threads.continue', $thread))->assertSessionHas('error');
});
