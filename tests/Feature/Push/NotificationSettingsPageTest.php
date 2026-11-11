<?php

use App\Models\PushDevice;
use App\Models\User;
use App\Services\Push\PushEvent;
use Inertia\Testing\AssertableInertia;

it('defaults to all four events on', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('settings.notifications'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Settings/Notifications', false)
            ->where('events', ['needs_you' => true, 'turn_finished' => true, 'stack_usage' => true, 'pull_request' => true])
            ->where('devices', [])
            ->where('isIosSupported', true));
});

it('stores event toggles and lists only my devices', function () {
    $user = User::factory()->create();
    PushDevice::factory()->forUser($user)->create(['endpoint' => 'https://push.example.test/mine']);
    PushDevice::factory()->forUser(User::factory()->create())->create();

    $this->actingAs($user)->put(route('settings.notifications.preferences'), [
        'needs_you' => true, 'turn_finished' => false, 'stack_usage' => true, 'pull_request' => false,
    ])->assertRedirect();

    expect($user->fresh()->wantsPush(PushEvent::TurnFinished))->toBeFalse()
        ->and($user->fresh()->wantsPush(PushEvent::NeedsYou))->toBeTrue();

    $this->actingAs($user)->get(route('settings.notifications', ['endpoint' => 'https://push.example.test/mine']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('devices', 1)
            ->where('devices.0.isCurrent', true)
            ->where('events.pull_request', false));
});

it('requires four booleans', function () {
    $this->actingAs(User::factory()->create())
        ->put(route('settings.notifications.preferences'), ['needs_you' => 'maybe'])
        ->assertSessionHasErrors(['needs_you', 'turn_finished', 'stack_usage', 'pull_request']);
});

it('never lists another account\'s devices', function () {
    $admin = User::factory()->create();
    PushDevice::factory()->forUser(User::factory()->create())->create();

    $this->actingAs($admin)->get(route('settings.notifications'))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('devices', 0));
});

it('exposes the device row shape the page renders', function () {
    $user = User::factory()->create();
    PushDevice::factory()->forUser($user)->create(['label' => 'Pixel', 'is_enabled' => false]);

    $this->actingAs($user)->get(route('settings.notifications'))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('devices.0', fn (AssertableInertia $device) => $device
                ->hasAll(['id', 'label', 'userAgent', 'isEnabled', 'lastUsedAt', 'lastError', 'isCurrent'])
                ->where('label', 'Pixel')
                ->where('isEnabled', false)
                ->where('isCurrent', false)));
});

it('shares a null vapid key when none is configured and follows the ios flag', function () {
    config(['webpush.vapid.public_key' => null, 'yak.push.ios_supported' => false]);

    $this->actingAs(User::factory()->create())->get(route('settings.notifications'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('vapidPublicKey', null)->where('isIosSupported', false));

    config(['webpush.vapid.public_key' => 'BKey123']);

    $this->actingAs(User::factory()->create())->get(route('settings.notifications'))
        ->assertInertia(fn (AssertableInertia $page) => $page->where('vapidPublicKey', 'BKey123'));
});

it('redirects guests to login', function () {
    $this->get(route('settings.notifications'))->assertRedirect();
});
