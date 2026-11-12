<?php

use App\Models\User;

beforeEach(function () {
    config()->set('yak.channels.email', ['webhook_password' => 'secret', 'inbound_address' => 'yak@geocodio.yak.build']);
    config()->set('mail.default', 'postmark');
    $this->actingAs(User::factory()->create(['has_seen_setup_card_at' => now()]));
});

it('shows the email channel with its address on the Channels page', function () {
    visit(route('channels'))
        ->assertSeeIn('[data-testid="channel-row-email"]', 'Forward an email with a note on top')
        ->assertSeeIn('[data-testid="email-channel-address"]', 'yak@geocodio.yak.build')
        ->assertSeeIn('[data-testid="email-channel-address"]', 'Manage your addresses')
        ->assertVisible('[data-testid="copy-address"]')
        ->assertNoJavascriptErrors()
        ->screenshot(filename: 'channels-email-row');
});
