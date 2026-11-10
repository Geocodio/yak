<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('channels page is accessible at /channels', function () {
    $this->get('/channels')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Channels/Index'));
});

test('renders a row for every known channel', function () {
    $this->get(route('channels'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Channels/Index')
            ->has('channels', 6)
            ->where('channels.0.slug', 'github')
            ->where('channels.1.slug', 'slack')
            ->where('channels.2.slug', 'linear')
            ->where('channels.3.slug', 'sentry')
            ->where('channels.4.slug', 'email')
            ->where('channels.5.slug', 'drone'));
});

test('email row shows the inbound address when the channel is connected', function () {
    config()->set('yak.channels.email', ['webhook_password' => 'secret', 'inbound_address' => 'yak@example.test']);
    config()->set('mail.default', 'postmark');

    $this->get(route('channels'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('channels.4.slug', 'email')
            ->where('channels.4.enabled', true)
            ->where('channels.4.address', 'yak@example.test'));
});

test('email row has no address when the channel is off', function () {
    config()->set('yak.channels.email', []);

    $this->get(route('channels'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('channels.4.statusLabel', 'Not connected')
            ->where('channels.4.address', null));
});

test('shares the inbound email address with every page only while the channel is on', function () {
    config()->set('yak.channels.email', ['webhook_password' => 'secret', 'inbound_address' => 'yak@example.test']);
    $this->get(route('channels'))->assertInertia(fn (Assert $page) => $page->where('emailToYakAddress', 'yak@example.test'));

    config()->set('yak.channels.email', []);
    $this->get(route('channels'))->assertInertia(fn (Assert $page) => $page->where('emailToYakAddress', null));
});

test('GitHub row is marked as required', function () {
    $this->get(route('channels'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('channels.0.slug', 'github')
            ->where('channels.0.required', true));
});

test('optional channels are marked "Not connected" when their env vars are blank', function () {
    config()->set('yak.channels.slack', []);
    config()->set('yak.channels.linear', []);

    $this->get(route('channels'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('channels.1.enabled', false)
            ->where('channels.1.statusLabel', 'Not connected')
            ->where('channels.2.enabled', false)
            ->where('channels.2.statusLabel', 'Not connected'));
});

test('each row exposes a docs URL pointing to the hosted docs', function () {
    $this->get(route('channels'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('channels.0.docsUrl', 'https://geocodio.github.io/yak/channels/#github-required')
            ->where('channels.1.docsUrl', 'https://geocodio.github.io/yak/channels/#slack-optional')
            ->where('channels.2.docsUrl', 'https://geocodio.github.io/yak/channels/#linear-optional'));
});

test('requires authentication', function () {
    auth()->logout();

    $this->get('/channels')->assertRedirect(route('login'));
});
