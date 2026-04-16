<?php

use App\Livewire\Channels\ChannelList;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

test('channels page is accessible at /channels', function () {
    $this->get('/channels')
        ->assertOk()
        ->assertSeeLivewire(ChannelList::class);
});

test('renders a card for every known channel', function () {
    $component = Livewire::test(ChannelList::class);

    foreach (['github', 'slack', 'linear', 'sentry', 'drone'] as $slug) {
        $component->assertSeeHtml("data-testid=\"channel-card-{$slug}\"");
    }
});

test('GitHub card is marked as required', function () {
    Livewire::test(ChannelList::class)
        ->assertSeeHtml('data-testid="channel-card-github"')
        ->assertSee('Required');
});

test('optional channels are marked "Not connected" when their env vars are blank', function () {
    config()->set('yak.channels.slack', []);
    config()->set('yak.channels.linear', []);

    Livewire::test(ChannelList::class)
        ->assertSee('Not connected');
});

test('each card exposes a Setup guide link pointing to the hosted docs', function () {
    $html = Livewire::test(ChannelList::class)->html();

    expect($html)
        ->toContain('Setup guide')
        ->toContain('https://geocodio.github.io/yak/channels/#slack-optional')
        ->toContain('https://geocodio.github.io/yak/channels/#linear-optional')
        ->toContain('https://geocodio.github.io/yak/channels/#github-required');
});

test('requires authentication', function () {
    auth()->logout();

    $this->get('/channels')->assertRedirect(route('login'));
});
