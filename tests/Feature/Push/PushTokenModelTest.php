<?php

use App\Enums\PushTokenKind;
use App\Models\Device;
use App\Models\DeviceLiveActivity;
use App\Models\DevicePushToken;
use App\Models\Thread;
use App\Models\User;
use App\Services\Devices\DeviceRevoker;
use Illuminate\Database\UniqueConstraintViolationException;

it('keeps one token per device and kind', function () {
    $token = alertTokenFor(User::factory()->create());

    expect(fn () => DevicePushToken::factory()->create(['device_id' => $token->device_id, 'kind' => PushTokenKind::Alert]))
        ->toThrow(UniqueConstraintViolationException::class);

    DevicePushToken::factory()->create(['device_id' => $token->device_id, 'kind' => PushTokenKind::LiveActivityStart]);

    expect(DevicePushToken::query()->where('device_id', $token->device_id)->count())->toBe(2);
});

it('keeps one activity row per device and thread', function () {
    $row = DeviceLiveActivity::factory()->create();

    expect(fn () => DeviceLiveActivity::factory()->create(['device_id' => $row->device_id, 'thread_id' => $row->thread_id]))
        ->toThrow(UniqueConstraintViolationException::class);

    DeviceLiveActivity::factory()->create(['device_id' => $row->device_id, 'thread_id' => Thread::factory()]);

    expect(DeviceLiveActivity::query()->where('device_id', $row->device_id)->count())->toBe(2);
});

it('removes tokens and activity rows when the device is revoked', function () {
    $user = User::factory()->create();
    $token = alertTokenFor($user);
    $other = alertTokenFor($user, str_repeat('b2', 32));
    DevicePushToken::factory()->create(['device_id' => $token->device_id, 'kind' => PushTokenKind::LiveActivityStart]);
    DeviceLiveActivity::factory()->create(['device_id' => $token->device_id]);
    DeviceLiveActivity::factory()->create(['device_id' => $other->device_id]);

    app(DeviceRevoker::class)->revoke($token->device);

    expect(Device::query()->whereKey($token->device_id)->exists())->toBeTrue()
        ->and(DevicePushToken::query()->where('device_id', $token->device_id)->count())->toBe(0)
        ->and(DeviceLiveActivity::query()->where('device_id', $token->device_id)->count())->toBe(0)
        ->and(DevicePushToken::query()->where('device_id', $other->device_id)->count())->toBe(1)
        ->and(DeviceLiveActivity::query()->where('device_id', $other->device_id)->count())->toBe(1);
});

it('lists only tokens of phones that are not revoked as deliverable', function () {
    $user = User::factory()->create();
    $phone = alertTokenFor($user);
    $revoked = alertTokenFor($user, str_repeat('c3', 32));
    $revoked->device->forceFill(['revoked_at' => now()])->save();
    $computer = Device::factory()->forUser($user)->create();
    DevicePushToken::factory()->create(['device_id' => $computer->id]);

    expect(DevicePushToken::query()->deliverable()->pluck('id')->all())->toBe([$phone->id]);
});

it('hides the token when serialized', function () {
    $token = alertTokenFor(User::factory()->create());

    expect($token->toArray())->not->toHaveKey('token')
        ->and($token->toJson())->not->toContain(str_repeat('a1', 32));
});

it('migrates down and up again', function () {
    $this->artisan('migrate:rollback', ['--step' => 4])->assertExitCode(0);
    expect(Schema::hasTable('device_push_tokens'))->toBeFalse()
        ->and(Schema::hasColumn('threads', 'live_activity_channel'))->toBeFalse();

    $this->artisan('migrate')->assertExitCode(0);
    expect(Schema::hasTable('device_live_activities'))->toBeTrue()
        ->and(Schema::hasColumn('threads', 'live_activity_channel'))->toBeTrue();
});
