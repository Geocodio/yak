<?php

use App\Models\DevicePairingCode;
use App\Models\User;
use App\Services\Devices\PairingCodes;

it('shows a QR code only after a click and names the phone once it pairs', function () {
    $this->actingAs(User::factory()->create());
    $page = visit('/settings/devices');

    $page->assertMissing('[data-testid="pairing-qr"]')
        ->click('[data-testid="add-phone"]')
        ->assertVisible('[data-testid="pairing-qr"]')
        ->assertSee('The code works once');

    parse_str((string) parse_url($page->attribute('[data-testid="pairing-qr"]', 'data-qr-payload'), PHP_URL_QUERY), $query);
    app(PairingCodes::class)->redeem($query['code'], ['name' => 'Test phone', 'model' => 'iPhone19,4', 'os' => '27.2.1', 'app_version' => '1.0.0']);

    $page->wait(4)->assertVisible('[data-testid="phone-paired-banner"]')->assertSee('Test phone is paired.')->assertMissing('[data-testid="pairing-qr"]');
    expect(DevicePairingCode::sole()->redeemed_at)->not->toBeNull();
});

it('offers a new code once the first one has expired', function () {
    $this->actingAs(User::factory()->create());
    $page = visit('/settings/devices')->click('[data-testid="add-phone"]')->assertVisible('[data-testid="pairing-qr"]');

    DevicePairingCode::query()->update(['expires_at' => now()->subSecond()]);

    $page->wait(4)->assertVisible('[data-testid="pairing-expired"]')
        ->click('Show a new code')
        ->assertVisible('[data-testid="pairing-qr"]');
    expect(DevicePairingCode::count())->toBe(2);
});

it('recovers from a failed pairing code request with a retry in place', function () {
    $this->actingAs(User::factory()->create());
    $this->app->bind(PairingCodes::class, fn () => throw new RuntimeException('boom'));
    $page = visit('/settings/devices')->click('[data-testid="add-phone"]')->assertVisible('[data-testid="pairing-failed"]');

    $this->app->forgetInstance(PairingCodes::class);
    $this->app->offsetUnset(PairingCodes::class);
    $page->click('Try again')->assertVisible('[data-testid="pairing-qr"]');
});
