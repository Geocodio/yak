<?php

use App\Models\Device;
use App\Models\HeldThreadEvent;
use App\Models\Thread;
use App\Models\User;

it('shows the teleport dialog with the command for a Yak-owned thread', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create();
    $this->actingAs($user);

    visit(route('threads.show', $thread))
        ->click('@teleport-button')
        ->assertVisible('@teleport-dialog')
        ->assertSeeIn('@teleport-dialog', 'Only committed work travels')
        ->assertSeeIn('@teleport-dialog', 'Or open it in one click')
        ->assertSeeIn('@teleport-dialog', "yak://teleport/{$thread->id}")
        ->assertSeeIn('@teleport-command', "yak teleport {$thread->id}")
        ->assertSeeIn('@teleport-dialog', "\$ yak teleport {$thread->id}")
        ->assertAttribute('@teleport-link', 'href', "yak://teleport/{$thread->id}")
        ->assertNoJavascriptErrors();
});

it('shows the on-device notice and held events', function () {
    $user = User::factory()->create();
    $device = Device::factory()->create(['user_id' => $user->id, 'name' => 'Work Mac']);
    $thread = Thread::factory()->ownedByDevice($device->id)->create(['owner_changed_at' => now()]);
    HeldThreadEvent::factory()->create(['thread_id' => $thread->id, 'title' => 'CI failed on the pull request']);
    $this->actingAs($user);

    visit(route('threads.show', $thread))
        ->assertSeeIn('@teleport-notice', 'On Work Mac')
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
        ->assertSeeIn('@teleport-command', '/yak teleport')
        ->assertVisible('@teleport-command-with-message')
        ->assertSeeIn('@teleport-dialog', 'read-only')
        ->assertNoJavascriptErrors();
});
