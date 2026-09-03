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
            ->has('channels', 5)
            ->where('channels.0.slug', 'github')
            ->where('channels.1.slug', 'slack')
            ->where('channels.2.slug', 'linear')
            ->where('channels.3.slug', 'sentry')
            ->where('channels.4.slug', 'drone'));
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
