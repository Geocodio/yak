<?php

use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
    $this->thread = Thread::factory()->create();
    $this->task = YakTask::factory()->create(['thread_id' => $this->thread->id]);
    $this->user = User::factory()->create();
    $this->turns = $this->mock(ThreadTurns::class);
    $this->turns->shouldNotReceive('start');
    $this->turns->shouldNotReceive('steer');
});

function clearAs(User $user, Thread $thread, string $message = '/clear')
{
    return test()->actingAs($user)->post(route('threads.messages.store', $thread), ['message' => $message]);
}

it('posting /clear creates a continued-from thread and redirects to it', function () {
    $response = clearAs($this->user, $this->thread);

    $new = Thread::query()->where('continued_from_thread_id', $this->thread->id)->firstOrFail();
    $response->assertRedirect(route('threads.show', $new))->assertSessionHas('success');
    expect($this->thread->refresh()->isOpen())->toBeTrue()
        ->and($this->task->refresh()->thread_id)->toBe($new->id);
});

it('posting /clear sends nothing to the runner and adds no session to the old thread', function () {
    clearAs($this->user, $this->thread);

    expect($this->thread->sessions()->count())->toBe(0)
        ->and(TaskRun::query()->count())->toBe(0);
});

it('posting /clear is refused while a turn is running', function () {
    TaskRun::factory()->create(['yak_task_id' => $this->task->id, 'thread_id' => $this->thread->id, 'turn_state' => TurnState::Running]);

    clearAs($this->user, $this->thread)->assertSessionHas('error');

    expect(Thread::query()->count())->toBe(1);
});

it('posting /clear is refused on a closed thread and a device-owned thread', function () {
    $closed = Thread::factory()->closed()->create();
    YakTask::factory()->create(['thread_id' => $closed->id]);
    $device = Thread::factory()->ownedByDevice()->create();
    YakTask::factory()->create(['thread_id' => $device->id]);

    clearAs($this->user, $closed)->assertSessionHas('error');
    clearAs($this->user, $device)->assertSessionHas('error');

    expect(Thread::query()->whereNotNull('continued_from_thread_id')->count())->toBe(0);
});

it('/clear with extra text goes to the agent as an ordinary message', function () {
    $turns = $this->mock(ThreadTurns::class);
    $turns->shouldReceive('start')->once();

    clearAs($this->user, $this->thread, '/clear please')->assertSessionHas('success');

    expect(Thread::query()->whereNotNull('continued_from_thread_id')->count())->toBe(0);
});

it("a private thread's /clear by another person answers 404 and the owner's keeps visibility", function () {
    $private = Thread::factory()->private()->create(['owner_user_id' => $this->user->id]);
    YakTask::factory()->create(['thread_id' => $private->id]);

    clearAs(User::factory()->create(), $private)->assertNotFound();
    clearAs($this->user, $private);

    $new = Thread::query()->where('continued_from_thread_id', $private->id)->firstOrFail();
    expect($new->visibility)->toBe($private->visibility)
        ->and($new->owner_user_id)->toBe($this->user->id);
});
