<?php

use App\Models\Repository;
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

it('hints at the email address in the New Task dialog', function () {
    Repository::factory()->create(['slug' => 'geocodio', 'is_active' => true]);

    visit('/tasks?new=1')
        ->assertSeeIn('[data-testid="new-task-email-hint"]', 'You can also forward an email to')
        ->assertSeeIn('[data-testid="new-task-email-hint"]', 'yak@geocodio.yak.build')
        ->assertNoJavascriptErrors()
        ->screenshot(filename: 'new-task-email-hint');
});

it('shows no hint while the email channel is off', function () {
    config()->set('yak.channels.email', []);
    Repository::factory()->create(['slug' => 'geocodio', 'is_active' => true]);

    visit('/tasks?new=1')
        ->assertVisible('[data-testid="new-task-dialog"]')
        ->assertMissing('[data-testid="new-task-email-hint"]');
});
