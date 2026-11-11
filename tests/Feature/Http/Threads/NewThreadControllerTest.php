<?php

use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('lists repos and picker data', function () {
    $user = User::factory()->create();
    Repository::factory()->create(['slug' => 'acme/widgets', 'name' => 'Widgets']);
    Repository::factory()->inactive()->create(['slug' => 'acme/old']);
    ClaudeAccount::factory()->default()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);

    $this->actingAs($user)->get(route('threads.new'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Threads/New')
        ->has('repos', 1)
        ->where('repos.0.slug', 'acme/widgets')
        ->where('defaultRepo', 'acme/widgets')
        ->has('picker.accounts', 1)
        ->has('picker.defaultsByRepo')
        ->has('picker.models'));
});

it('passes the email-to-Yak address only while the email channel is enabled', function () {
    $user = User::factory()->create();

    config()->set('yak.channels.email', ['webhook_password' => 'secret', 'inbound_address' => 'yak@example.test']);
    $this->actingAs($user)->get(route('threads.new'))->assertInertia(fn (Assert $page) => $page
        ->where('emailToYakAddress', 'yak@example.test'));

    config()->set('yak.channels.email', []);
    $this->actingAs($user)->get(route('threads.new'))->assertInertia(fn (Assert $page) => $page
        ->where('emailToYakAddress', null));
});

it('hides unusable accounts', function () {
    $user = User::factory()->create();
    ClaudeAccount::factory()->create(['owner_user_id' => $user->id, 'validated_at' => now()]);
    ClaudeAccount::factory()->create(['validated_at' => now()]);
    ClaudeAccount::factory()->create(['owner_user_id' => $user->id, 'name' => 'validation-abc']);

    $this->actingAs($user)->get(route('threads.new'))->assertInertia(fn (Assert $page) => $page
        ->has('picker.accounts', 1)
        ->where('defaultRepo', null));
});
