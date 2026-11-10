<?php

use App\Models\User;
use App\Models\UserEmailAddress;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    config()->set('yak.channels.email.webhook_password', 'secret');
    config()->set('yak.channels.email.inbound_address', 'yak@geocodio.yak.build');
});

it('confirms an added address with a visible message', function () {
    Mail::fake();
    $this->actingAs(User::factory()->create());

    visit(route('profile.edit'))
        ->fill('@add-email-address', 'mhansen@hey.com')
        ->press('Add address')
        ->assertSee('Confirmation sent to mhansen@hey.com.')
        ->assertNoJavascriptErrors();
});

it('shows a visible error when adding an address fails on the server', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('MISCONF Redis is unable to persist to disk'));
    $this->actingAs(User::factory()->create());

    visit(route('profile.edit'))
        ->fill('@add-email-address', 'mhansen@hey.com')
        ->press('Add address')
        ->assertSee('Something went wrong on the server.');

    expect(UserEmailAddress::query()->count())->toBe(0);
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
