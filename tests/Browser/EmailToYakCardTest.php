<?php

use App\Models\User;
use App\Models\UserEmailAddress;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config()->set('yak.channels.email.webhook_password', 'secret');
    config()->set('yak.channels.email.inbound_address', 'yak@geocodio.yak.build');
});

it('lists sender addresses and adds a new one', function () {
    Mail::fake();
    $user = User::factory()->create(['email' => 'mathias@geocod.io']);
    UserEmailAddress::factory()->confirmed()->for($user)->create(['email' => 'mhansen@hey.com']);
    $this->actingAs($user);

    visit(route('profile.edit'))
        ->assertSee('Email to Yak')
        ->assertSee('yak@geocodio.yak.build')
        ->assertSee('Sign-in address')
        ->assertSee('Confirmed')
        ->fill('@add-email-address', 'mathias@geocdio.io')
        ->press('Add address')
        ->assertSee('Waiting for confirmation')
        ->assertSee('mathias@geocdio.io')
        ->assertNoJavascriptErrors()
        ->screenshot(filename: 'email-to-yak-card');

    expect($user->emailAddresses()->where('email', 'mathias@geocdio.io')->exists())->toBeTrue();
});
