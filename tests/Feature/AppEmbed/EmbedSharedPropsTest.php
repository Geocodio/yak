<?php

use App\Models\Thread;
use App\Models\User;
use Inertia\Testing\AssertableInertia;

beforeEach(fn () => $this->withoutVite());

it('marks the page as embedded and skips the drawer data on the embed session', function () {
    [$user, $device] = embedPhone();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);

    actingAsEmbed(embedSessionData($user, $device));
    $this->get('/app/embed/threads/' . $thread->public_id)->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('embed', ['bridge' => 1])
            ->where('threadList', null)
            ->where('nav', null)
            ->where('setup', null)
            ->where('operationsPanel', null));
});

it('leaves the shared props of a normal session unchanged', function () {
    $user = User::factory()->create();
    $thread = Thread::factory()->create(['created_by_user_id' => $user->id]);

    $this->actingAs($user)->get(route('threads.show', $thread))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('embed', null)
            ->has('threadList')
            ->has('nav.activeTaskCount')
            ->has('nav.needsYouCount'));
});
