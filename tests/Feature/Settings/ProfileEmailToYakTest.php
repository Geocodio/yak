<?php

use App\Models\User;
use App\Models\UserEmailAddress;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the inbound address and the user\'s sender addresses when the channel is on', function () {
    config()->set('yak.channels.email.webhook_password', 'secret');
    config()->set('yak.channels.email.inbound_address', 'yak@geocodio.yak.build');

    $user = User::factory()->create();
    $confirmed = UserEmailAddress::factory()->confirmed()->for($user)->create(['email' => 'a@hey.com']);
    $pending = UserEmailAddress::factory()->unconfirmed()->for($user)->create(['email' => 'b@hey.com']);
    UserEmailAddress::factory()->confirmed()->create(['email' => 'someone-else@hey.com']);

    $this->actingAs($user)
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('profile.emailToYak.inboundAddress', 'yak@geocodio.yak.build')
            ->where('profile.emailToYak.addresses', [
                ['id' => $confirmed->id, 'email' => 'a@hey.com', 'confirmed' => true],
                ['id' => $pending->id, 'email' => 'b@hey.com', 'confirmed' => false],
            ])
            ->etc());
});

it('hides the card when the email channel is off', function () {
    config()->set('yak.channels.email.webhook_password', null);

    $this->actingAs(User::factory()->create())
        ->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page->where('profile.emailToYak', null)->etc());
});
