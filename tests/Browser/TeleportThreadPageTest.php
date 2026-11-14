<?php

use App\Enums\TurnState;
use App\Models\Device;
use App\Models\HeldThreadEvent;
use App\Models\TaskRun;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\User;

it('shows the teleport dialog with the command for a Yak-owned thread', function () {
    $user = User::factory()->create();
    Device::factory()->forUser($user)->create();
    $thread = Thread::factory()->create();
    $this->actingAs($user);

    visit(route('threads.show', $thread))
        ->click('@teleport-button')
        ->assertVisible('@teleport-dialog')
        ->assertSeeIn('@teleport-dialog', 'Only committed work travels')
        ->assertSeeIn('@teleport-dialog', 'Or open it in one click')
        ->assertSeeIn('@teleport-dialog', "yak://teleport/{$thread->public_id}")
        ->assertSeeIn('@teleport-command', "yak teleport {$thread->public_id}")
        ->assertSeeIn('@teleport-dialog', "\$ yak teleport {$thread->public_id}")
        ->assertAttribute('@teleport-link', 'href', "yak://teleport/{$thread->public_id}")
        ->assertNoJavascriptErrors();
});

it('shows the Teleport setup instead of the command when the person has no computer', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create();
    $this->actingAs($user);

    visit(route('threads.show', $thread))
        ->click('@teleport-button')
        ->assertVisible('@teleport-dialog')
        ->assertPresent('[data-testid="teleport-install-command"]')
        ->assertMissing('@teleport-command')
        ->assertNoJavascriptErrors();
});

it('shows the on-device notice and held events', function () {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id, 'name' => 'Work Mac']);
    $thread = Thread::factory()->ownedByDevice($device->id)->create(['owner_changed_at' => now()]);
    HeldThreadEvent::factory()->create(['thread_id' => $thread->id, 'title' => 'CI failed on the pull request']);
    $this->actingAs($user);

    visit(route('threads.show', $thread))
        ->assertSeeIn('@teleport-notice', 'On Work Mac since')
        ->assertVisible('@teleport-up-banner')
        ->assertMissing('@teleport-show-command')
        ->assertSeeIn('@held-events-count', '1')
        ->assertVisible('@held-events')
        ->assertSeeIn('@held-event-card', 'CI failed on the pull request')
        ->assertNoJavascriptErrors();
});

it('shows the teleport to Yak dialog with the slash command on a device-owned thread', function () {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id, 'name' => 'Work Mac']);
    $thread = Thread::factory()->ownedByDevice($device->id)->create(['owner_changed_at' => now()]);
    $this->actingAs($user);

    visit(route('threads.show', $thread))
        ->click('@teleport-up')
        ->assertVisible('@teleport-dialog')
        ->assertSeeIn('@teleport-command', '/yak:yak teleport')
        ->assertVisible('@teleport-command-with-message')
        ->assertSeeIn('@teleport-dialog', 'read-only')
        ->assertNoJavascriptErrors();
});

it('shows a teammate who has the thread without a teleport button', function () {
    $owner = User::factory()->create(['name' => 'Mia Jensen']);
    $device = Device::factory()->create(['user_id' => $owner->id, 'name' => 'Work Mac']);
    $thread = Thread::factory()->ownedByDevice($device->id)->create(['owner_changed_at' => now()]);
    HeldThreadEvent::factory()->create(['thread_id' => $thread->id]);
    $this->actingAs(User::factory()->create());

    visit(route('threads.show', $thread))
        ->assertSeeIn('@teleport-notice', 'Mia Jensen has this on their computer')
        ->assertSeeIn('@teleport-notice', 'Only Mia can teleport it back')
        ->assertMissing('@teleport-up-banner')
        ->assertSeeIn('@held-events', 'It told Mia.')
        ->assertNoJavascriptErrors();
});

it('opens the command dialog from the waiting banner', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['waiting_for_computer_at' => now()]);
    $this->actingAs($user);

    visit(route('threads.show', $thread))
        ->assertSeeIn('@teleport-notice', "yak teleport {$thread->public_id}")
        ->click('@teleport-show-command')
        ->assertVisible('@teleport-dialog')
        ->assertNoJavascriptErrors();
});

it('offers stop now while a teleport waits for a running turn', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create();
    Teleport::factory()->down()->create(['thread_id' => $thread->id, 'user_id' => $user->id]);
    TaskRun::factory()->create(['thread_id' => $thread->id, 'turn_state' => TurnState::Running]);
    $this->actingAs($user);

    visit(route('threads.show', $thread))
        ->assertSeeIn('@teleport-notice', 'is waiting for this turn to finish')
        ->assertVisible('@teleport-stop-now')
        ->assertNoJavascriptErrors();
});
