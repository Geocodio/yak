<?php

use App\Enums\TeleportState;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\User;

beforeEach(fn () => $this->withoutVite());

it('marks the thread waiting without touching the running turn', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create();

    $this->actingAs($user)->post(route('threads.teleport-request.store', $thread), ['running_turn' => 'stop'])->assertRedirect();

    $thread->refresh();
    expect($thread->waiting_for_computer_at)->not->toBeNull()
        ->and($thread->teleport_requested_running_turn)->toBe('stop')
        ->and($thread->owner_side->value)->toBe('yak');
});

it('rejects a request on a device-owned thread', function () {
    $thread = Thread::factory()->ownedByDevice()->create();

    $this->actingAs(User::factory()->create())->post(route('threads.teleport-request.store', $thread))->assertStatus(409);

    expect($thread->refresh()->waiting_for_computer_at)->toBeNull();
});

it('rejects a request while a teleport is open', function () {
    $thread = Thread::factory()->create();
    Teleport::factory()->down()->create(['thread_id' => $thread->id]);

    $this->actingAs(User::factory()->create())->post(route('threads.teleport-request.store', $thread))->assertStatus(409);
});

it('rejects an invalid running turn choice', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('threads.teleport-request.store', Thread::factory()->create()), ['running_turn' => 'nope'])
        ->assertSessionHasErrors('running_turn');
});

it('clears the mark on destroy', function () {
    $thread = Thread::factory()->create(['waiting_for_computer_at' => now(), 'teleport_requested_running_turn' => 'wait']);

    $this->actingAs(User::factory()->create())->delete(route('threads.teleport-request.destroy', $thread))->assertRedirect();

    $thread->refresh();
    expect($thread->waiting_for_computer_at)->toBeNull()->and($thread->teleport_requested_running_turn)->toBeNull();
});

it('cancels an open teleport on destroy', function () {
    $thread = Thread::factory()->create();
    $teleport = Teleport::factory()->down()->create(['thread_id' => $thread->id]);

    $this->actingAs(User::factory()->create())->delete(route('threads.teleport-request.destroy', $thread))->assertRedirect();

    expect($teleport->refresh()->state)->toBe(TeleportState::Cancelled)
        ->and($teleport->failure_code)->toBe('user_cancelled')
        ->and($thread->refresh()->owner_side->value)->toBe('yak');
});

it('answers 404 for another user\'s private thread on both routes', function () {
    $thread = Thread::factory()->private(User::factory()->create())->create();
    $other = User::factory()->create(['email' => 'admin@example.com']);

    $this->actingAs($other)->post(route('threads.teleport-request.store', $thread))->assertNotFound();
    $this->actingAs($other)->delete(route('threads.teleport-request.destroy', $thread))->assertNotFound();
    $this->actingAs($other)->post(route('threads.teleport-request.store', 999999))->assertNotFound();
});

it('rejects guests', function () {
    $this->post(route('threads.teleport-request.store', Thread::factory()->create()))->assertRedirectToRoute('login');
});
