<?php

use App\Enums\DeviceKind;
use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Models\User;
use App\Services\Devices\DeviceFlowException;
use App\Services\Devices\PairingCodes;

function pairingReport(): array
{
    return ['name' => 'Test phone 🌎', 'os' => '27.2.1', 'model' => 'iPhone19,4', 'app_version' => '1.0.0'];
}

it('redeems a code once', function () {
    ['code' => $code] = app(PairingCodes::class)->create($user = User::factory()->create());
    $first = app(PairingCodes::class)->redeem($code, pairingReport());

    expect($first['device'])->kind->toBe(DeviceKind::Phone)->user_id->toBe($user->id)
        ->and(fn () => app(PairingCodes::class)->redeem($code, pairingReport()))->toThrow(DeviceFlowException::class, 'pairing code is not valid')
        ->and(Device::count())->toBe(1)
        ->and(DevicePairingCode::sole()->device_id)->toBe($first['device']->id);
});

it('lets exactly one of two scans of the same code win', function () {
    ['code' => $code] = app(PairingCodes::class)->create(User::factory()->create());
    $outcomes = [];

    foreach ([1, 2] as $phone) {
        try {
            app(PairingCodes::class)->redeem($code, ['name' => "Phone {$phone}"] + pairingReport());
            $outcomes[] = 'paired';
        } catch (DeviceFlowException $exception) {
            $outcomes[] = $exception->errorCode;
        }
    }

    expect($outcomes)->toBe(['paired', 'pairing_code_invalid'])->and(Device::count())->toBe(1);
});

it('loses the write when another scan claims the code between the read and the write', function () {
    ['code' => $code, 'pairingCode' => $pairingCode] = app(PairingCodes::class)->create(User::factory()->create());
    DevicePairingCode::retrieved(fn () => DevicePairingCode::query()->whereKey($pairingCode->id)->update(['redeemed_at' => now()]));

    try {
        app(PairingCodes::class)->redeem($code, pairingReport());
        $this->fail('The second scan must be refused.');
    } catch (DeviceFlowException $exception) {
        expect($exception->errorCode)->toBe('pairing_code_invalid');
    } finally {
        DevicePairingCode::flushEventListeners();
    }

    DevicePairingCode::flushEventListeners();

    expect(Device::count())->toBe(0);
});

it('prunes codes that expired more than a day ago', function () {
    $old = DevicePairingCode::factory()->create(['expires_at' => now()->subDays(2)]);
    $recent = DevicePairingCode::factory()->create(['expires_at' => now()->subHour()]);

    $this->artisan('model:prune', ['--model' => [DevicePairingCode::class]]);

    expect(DevicePairingCode::find($old->id))->toBeNull()->and(DevicePairingCode::find($recent->id))->not->toBeNull();
});

it('expires after 120 seconds', function () {
    ['code' => $code] = app(PairingCodes::class)->create(User::factory()->create());

    $this->travel(121)->seconds();

    try {
        app(PairingCodes::class)->redeem($code, pairingReport());
        $this->fail('expected the code to be expired');
    } catch (DeviceFlowException $exception) {
        expect($exception->errorCode)->toBe('pairing_code_expired');
    }

    expect(Device::count())->toBe(0);
});

it('refuses a code whose user was deleted', function () {
    ['code' => $code] = app(PairingCodes::class)->create($user = User::factory()->create());
    $user->delete();

    try {
        app(PairingCodes::class)->redeem($code, pairingReport());
        $this->fail('expected the code to be invalid');
    } catch (DeviceFlowException $exception) {
        expect($exception->errorCode)->toBe('pairing_code_invalid');
    }

    expect(Device::count())->toBe(0);
});

it('stores only a hash of the code', function () {
    ['code' => $code] = app(PairingCodes::class)->create(User::factory()->create());
    $row = DevicePairingCode::sole();

    expect($row->code_hash)->toBe(hash('sha256', $code))
        ->and(json_encode(DB::table('device_pairing_codes')->first()))->not->toContain($code)
        ->and(strlen($code))->toBe(43);
});

it('clears the kept token of a redeemed code once its life has passed', function () {
    ['code' => $code] = app(PairingCodes::class)->create(User::factory()->create());
    app(PairingCodes::class)->redeem($code, pairingReport(), 'request-1');

    app(PairingCodes::class)->clearRepeatableTokens();
    expect(DevicePairingCode::sole()->encrypted_token)->not->toBeNull();

    $this->travel(config('yak.pairing.code_ttl_seconds') + 1)->seconds();
    app(PairingCodes::class)->clearRepeatableTokens();

    expect(DevicePairingCode::sole())->encrypted_token->toBeNull()->request_id_hash->toBeNull();
});
