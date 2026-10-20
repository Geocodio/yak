<?php

use App\Models\User;

it('shows the connected account on the profile page', function () {
    $this->actingAs(User::factory()->withGitHub(4242, 'mhansen')->create());

    visit(route('profile.edit'))
        ->assertSee('GitHub account')
        ->assertSee('mhansen')
        ->assertSee('Connected')
        ->assertSee('Disconnect')
        ->assertNoJavascriptErrors();
});

it('offers to connect GitHub when no account is linked', function () {
    $this->actingAs(User::factory()->create());

    visit(route('profile.edit'))
        ->assertSee('Connect GitHub')
        ->assertDontSee('Connected')
        ->assertNoJavascriptErrors();
});
