<?php

use App\Enums\TeleportState;
use App\Models\Device;
use App\Models\HeldThreadEvent;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->withoutVite());

it('renders the Threads/Show page with the teleport state for every state', function (string $state, Closure $make) {
    $user = User::factory()->create();
    $thread = $make($user);

    $this->actingAs($user)->get(route('threads.show', $thread))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Threads/Show')
            ->where('teleport.state', $state === 'failed' ? 'none' : $state)
            ->where('thread.ownerSide', $state === 'on_device' ? 'device' : 'yak')
            ->where('teleport.link', "yak://teleport/{$thread->id}"));
})->with([
    'none' => ['none', fn () => Thread::factory()->create()],
    'waiting' => ['waiting', fn () => Thread::factory()->create(['waiting_for_computer_at' => now()])],
    'teleporting' => ['teleporting', function () {
        $thread = Thread::factory()->create();
        Teleport::factory()->down()->inState(TeleportState::Preparing)->create(['thread_id' => $thread->id]);

        return $thread;
    }],
    'on_device' => ['on_device', function (User $user) {
        $thread = Thread::factory()->ownedByDevice(Device::factory()->create(['user_id' => $user->id])->id)->create();
        HeldThreadEvent::factory()->create(['thread_id' => $thread->id]);

        return $thread;
    }],
    'failed' => ['failed', function () {
        $thread = Thread::factory()->create();
        Teleport::factory()->down()->inState(TeleportState::Failed)->create(['thread_id' => $thread->id, 'failure_code' => 'x', 'failure_message' => 'Push was rejected.']);

        return $thread;
    }],
]);
