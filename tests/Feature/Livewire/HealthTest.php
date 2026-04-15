<?php

use App\Livewire\Health;
use App\Models\User;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    Process::fake([
        'pgrep *' => Process::result(output: '12345'),
        '*ls-remote*' => Process::result(output: 'abc123 HEAD'),
        'claude *' => Process::result(output: 'claude v1.0.0'),
    ]);

    // Disable all channels by default; individual tests enable what they need
    config([
        'yak.channels.slack.bot_token' => null,
        'yak.channels.slack.signing_secret' => null,
        'yak.channels.linear.webhook_secret' => null,
        'yak.channels.sentry.auth_token' => null,
        'yak.channels.sentry.webhook_secret' => null,
        'yak.channels.sentry.org_slug' => null,
        'yak.channels.drone.url' => null,
        'yak.channels.drone.token' => null,
        'yak.channels.github.app_id' => null,
        'yak.channels.github.private_key' => null,
        'yak.channels.github.webhook_secret' => null,
    ]);
});

it('renders the health page header', function () {
    Livewire::test(Health::class)
        ->assertSee('Health')
        ->assertSee('Refresh all');
});

it('renders the System section with all system check names as placeholders', function () {
    Livewire::test(Health::class)
        ->assertSee('System')
        ->assertSee('Queue Worker')
        ->assertSee('Last Task Completed')
        ->assertSee('Incus Daemon')
        ->assertSee('Sandbox Base Template')
        ->assertSee('Claude CLI')
        ->assertSee('Claude Max Session')
        ->assertSee('Repositories Reachable')
        ->assertSee('Webhook Signatures');
});

it('hides the Channels section when no channels are enabled', function () {
    Livewire::test(Health::class)
        ->assertDontSee('Channels');
});

it('shows only enabled channels in the Channels section', function () {
    config([
        'yak.channels.slack.bot_token' => 'xoxb',
        'yak.channels.slack.signing_secret' => 'sig',
    ]);

    Livewire::test(Health::class)
        ->assertSee('Channels')
        ->assertSee('Slack')
        ->assertDontSee('Sentry')
        ->assertDontSee('Drone CI');
});

it('refresh all dispatches the health-refresh event', function () {
    Livewire::test(Health::class)
        ->call('refreshAll')
        ->assertDispatched('health-refresh');
});

it('requires authentication', function () {
    auth()->logout();

    $this->get(route('health'))
        ->assertRedirect(route('login'));
});
