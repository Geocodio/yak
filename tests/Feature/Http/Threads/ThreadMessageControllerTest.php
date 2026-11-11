<?php

use App\Enums\TurnState;
use App\Models\Device;
use App\Models\PendingSteeringMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadBusy;
use App\Services\Threads\ThreadTurns;
use Mockery\MockInterface;

function messageThread(array $state = []): array
{
    $thread = Thread::factory()->create($state);
    $task = YakTask::factory()->create(['thread_id' => $thread->id]);

    return [$thread, $task, User::factory()->create()];
}

function mockTurns(): MockInterface
{
    return test()->mock(ThreadTurns::class);
}

it('starts a turn when the thread is idle', function () {
    [$thread, $task, $user] = messageThread();
    $turns = mockTurns();
    $turns->shouldReceive('start')->once()->withArgs(fn ($t, $text, $driver, $model) => $t->is($task) && $text === 'hello' && $driver->is($user) && $model === 'opus')->andReturn(new TaskRun);
    $turns->shouldNotReceive('steer');

    $this->actingAs($user)->post(route('threads.messages.store', $thread), ['message' => 'hello', 'model' => 'opus'])
        ->assertRedirect()->assertSessionHas('success');
});

it('steers when a turn is running', function () {
    [$thread, $task, $user] = messageThread();
    TaskRun::factory()->create(['yak_task_id' => $task->id, 'thread_id' => $thread->id, 'turn_state' => TurnState::Running]);
    $turns = mockTurns();
    $turns->shouldReceive('steer')->once()->withArgs(fn ($t, $text, $sender, $model) => $t->is($thread) && $text === 'more' && $model === 'haiku')->andReturn(new PendingSteeringMessage(['id' => 1]));
    $turns->shouldNotReceive('start');

    $this->actingAs($user)->post(route('threads.messages.store', $thread), ['message' => 'more', 'model' => 'haiku'])
        ->assertSessionHas('success');
});

dataset('privateThreadOutsiders', ['second user' => [false], 'admin' => [true]]);

function outsider(bool $isAdmin): User
{
    $user = User::factory()->create();
    if ($isAdmin) {
        config(['claude-accounts.admin_user_ids' => [$user->id]]);
    }

    return $user;
}

it('404s for a second user and an admin on a private thread', function (bool $isAdmin) {
    $thread = Thread::factory()->private()->create();
    YakTask::factory()->create(['thread_id' => $thread->id]);
    $other = outsider($isAdmin);
    $turns = mockTurns();
    $turns->shouldNotReceive('start');
    $turns->shouldNotReceive('steer');
    $turns->shouldNotReceive('stop');

    $this->actingAs($other)->post(route('threads.messages.store', $thread), ['message' => 'hi'])->assertNotFound();
})->with('privateThreadOutsiders');

it('404s stop for a second user and an admin on a private thread', function (bool $isAdmin) {
    $thread = Thread::factory()->private()->create();
    YakTask::factory()->create(['thread_id' => $thread->id]);
    $other = outsider($isAdmin);
    mockTurns()->shouldNotReceive('stop');

    $this->actingAs($other)->post(route('threads.stop', $thread))->assertNotFound();
})->with('privateThreadOutsiders');

it('refuses while a computer owns the thread', function () {
    [$thread, , $user] = messageThread();
    $thread->forceFill(['owner_side' => 'device', 'owner_device_id' => Device::factory()->create()->id])->save();
    $turns = mockTurns();
    $turns->shouldNotReceive('start');
    $turns->shouldNotReceive('steer');

    $this->actingAs($user)->post(route('threads.messages.store', $thread), ['message' => 'hi'])
        ->assertSessionHas('error', fn (string $message) => str_starts_with($message, 'Your computer is working on this thread'));
});

it('refuses on a closed thread', function () {
    [$thread, , $user] = messageThread(['status' => 'closed', 'closed_at' => now()]);
    $turns = mockTurns();
    $turns->shouldNotReceive('start');
    $turns->shouldNotReceive('steer');

    $this->actingAs($user)->post(route('threads.messages.store', $thread), ['message' => 'hi'])->assertSessionHas('error');
});

it('turns ThreadBusy into a friendly error', function () {
    [$thread, , $user] = messageThread();
    mockTurns()->shouldReceive('start')->once()->andThrow(new ThreadBusy('busy'));

    $this->actingAs($user)->post(route('threads.messages.store', $thread), ['message' => 'hi'])->assertSessionHas('error');
});

it('rejects empty, 20001-char and unknown-model input', function (array $input, string $field) {
    [$thread, , $user] = messageThread();
    $turns = mockTurns();
    $turns->shouldNotReceive('start');

    $this->actingAs($user)->post(route('threads.messages.store', $thread), $input)->assertSessionHasErrors($field);
})->with([
    'empty' => [['message' => ''], 'message'],
    'too long' => [['message' => str_repeat('a', 20001)], 'message'],
    'unknown model' => [['message' => 'hi', 'model' => 'gpt'], 'model'],
]);

it('stop interrupts via ThreadTurns::stop', function () {
    [$thread, , $user] = messageThread();
    mockTurns()->shouldReceive('stop')->once()->withArgs(fn ($t) => $t->is($thread));

    $this->actingAs($user)->post(route('threads.stop', $thread))->assertRedirect();
});
