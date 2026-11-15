<?php

use App\Enums\PushTokenKind;
use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\ContractFixture;

function pushTokenPath(string $kind = 'alert'): string
{
    return "/api/app/v1/push-tokens/{$kind}";
}

beforeEach(fn () => fakeApns());

it('stores a token in lowercase and answers 204', function (string $kind) {
    [$device, $headers] = phoneWithHeaders();

    $this->putJson(pushTokenPath($kind), ['token' => str_repeat('AB', 32), 'environment' => 'production'], $headers)->assertNoContent();

    $row = DevicePushToken::query()->where('device_id', $device->id)->sole();
    expect($row->kind->value)->toBe($kind)->and($row->token)->toBe(str_repeat('ab', 32))->and($row->environment)->toBe('production');
})->with(['alert', 'live_activity_start']);

it('changes nothing on a repeat', function () {
    [, $headers] = phoneWithHeaders();
    $body = ['token' => str_repeat('ab', 32), 'environment' => 'production'];

    $this->putJson(pushTokenPath(), $body, $headers)->assertNoContent();
    $row = DevicePushToken::query()->sole();
    $this->travel(5)->minutes();
    $this->putJson(pushTokenPath(), $body, $headers)->assertNoContent();

    expect(DevicePushToken::query()->count())->toBe(1)
        ->and($row->fresh()->updated_at->equalTo($row->updated_at))->toBeTrue();
});

it('replaces the token of the device and clears the last error', function () {
    [$device, $headers] = phoneWithHeaders();
    DevicePushToken::factory()->create(['device_id' => $device->id, 'kind' => PushTokenKind::Alert, 'token' => str_repeat('cd', 32), 'last_error' => '400 BadDeviceToken']);

    $this->putJson(pushTokenPath(), ['token' => str_repeat('ab', 32), 'environment' => 'production'], $headers)->assertNoContent();

    $row = DevicePushToken::query()->sole();
    expect($row->token)->toBe(str_repeat('ab', 32))->and($row->last_error)->toBeNull();
});

it('moves a token that another device registered', function () {
    $other = alertTokenFor(User::factory()->create(['email' => 'other@example.test']), str_repeat('ab', 32));
    $otherStart = DevicePushToken::factory()->create(['device_id' => $other->device_id, 'kind' => PushTokenKind::LiveActivityStart, 'token' => str_repeat('ee', 32)]);
    [$device, $headers] = phoneWithHeaders();

    $this->putJson(pushTokenPath(), ['token' => str_repeat('ab', 32), 'environment' => 'production'], $headers)->assertNoContent();

    expect(DevicePushToken::query()->whereKey($other->id)->exists())->toBeFalse()
        ->and(DevicePushToken::query()->whereKey($otherStart->id)->exists())->toBeTrue()
        ->and(DevicePushToken::query()->where('device_id', $device->id)->count())->toBe(1);
});

it('stores the token without a key', function () {
    config(['yak.apns.key_path' => '']);
    [$device, $headers] = phoneWithHeaders();

    $this->putJson(pushTokenPath(), ['token' => str_repeat('ab', 32), 'environment' => 'sandbox'], $headers)->assertNoContent();

    expect(DevicePushToken::query()->where('device_id', $device->id)->sole()->environment)->toBe('sandbox');
});

it('refuses a build from the other environment', function () {
    [, $headers] = phoneWithHeaders();

    $response = $this->putJson(pushTokenPath(), ['token' => str_repeat('ab', 32), 'environment' => 'sandbox'], $headers)
        ->assertStatus(409)
        ->assertJsonPath('error', 'push_environment_mismatch')
        ->assertJsonPath('message', 'This Yak sends notifications to production builds only. Install the build from TestFlight to get notifications.')
        ->assertJsonPath('details', ['expected' => 'production', 'given' => 'sandbox']);

    expect(DevicePushToken::query()->count())->toBe(0);
    ContractFixture::assertMatches('app', 'error-push-environment-mismatch', $response->json());
});

it('validates the token and environment', function (array $body, string $field) {
    [, $headers] = phoneWithHeaders();

    $this->putJson(pushTokenPath(), $body, $headers)
        ->assertStatus(422)
        ->assertJsonPath('error', 'validation_failed')
        ->assertJsonStructure(['details' => ['errors' => [$field]]]);
})->with([
    '63 characters' => [['token' => str_repeat('a', 63), 'environment' => 'production'], 'token'],
    '201 characters' => [['token' => str_repeat('a', 201), 'environment' => 'production'], 'token'],
    'not hex' => [['token' => str_repeat('zz', 32), 'environment' => 'production'], 'token'],
    'no environment' => [['token' => str_repeat('ab', 32)], 'environment'],
    'unknown environment' => [['token' => str_repeat('ab', 32), 'environment' => 'staging'], 'environment'],
]);

it('answers not found for an unknown kind', function (string $kind) {
    [, $headers] = phoneWithHeaders();

    $this->putJson(pushTokenPath($kind), ['token' => str_repeat('ab', 32), 'environment' => 'production'], $headers)->assertNotFound()->assertJsonPath('error', 'not_found');
    $this->deleteJson(pushTokenPath($kind), [], $headers)->assertNotFound()->assertJsonPath('error', 'not_found');
})->with(['widget', 'live_activity']);

it('removes a token and answers 204 when there is none', function () {
    [$device, $headers] = phoneWithHeaders();
    DevicePushToken::factory()->create(['device_id' => $device->id, 'kind' => PushTokenKind::Alert]);
    $keep = DevicePushToken::factory()->create(['device_id' => $device->id, 'kind' => PushTokenKind::LiveActivityStart]);

    $this->deleteJson(pushTokenPath(), [], $headers)->assertNoContent();
    $this->deleteJson(pushTokenPath(), [], $headers)->assertNoContent();

    expect(DevicePushToken::query()->pluck('id')->all())->toBe([$keep->id]);
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    $body = ['token' => str_repeat('ab', 32), 'environment' => 'production'];

    $this->putJson(pushTokenPath(), $body, ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');
    $this->deleteJson(pushTokenPath(), [], ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithHeaders();
    $device->update(['revoked_at' => now()]);
    $userId = $device->user_id;
    $this->putJson(pushTokenPath(), $body, $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $userId], isPhone: false);
    $this->putJson(pushTokenPath(), $body, $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
    expect(DevicePushToken::query()->count())->toBe(0);
});

it('answers 503 when the database lock wait runs out', function () {
    [, $headers] = phoneWithHeaders();
    DevicePushToken::creating(fn () => throw tap(new QueryException('mysql', 'select 1', [], new PDOException('Lock wait timeout exceeded')), fn ($exception) => $exception->errorInfo = [null, 1205, 'Lock wait timeout exceeded']));

    $this->putJson(pushTokenPath(), ['token' => str_repeat('ab', 32), 'environment' => 'production'], $headers)
        ->assertStatus(503)
        ->assertJsonPath('error', 'unavailable')
        ->assertJsonPath('message', 'Yak is busy with another request for this device. Try again in a few seconds.');
});

it('locks the device row before it writes the token, so a concurrent repeat cannot hit the unique key', function () {
    [, $headers] = phoneWithHeaders();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });

    $this->putJson(pushTokenPath(), ['token' => str_repeat('ab', 32), 'environment' => 'production'], $headers)->assertNoContent();

    $lockIndex = collect($queries)->search(fn ($sql) => str_starts_with($sql, 'select') && str_contains($sql, 'from "devices" where "devices"."id" = ') && ! str_contains($sql, 'token_hash'));
    $insertIndex = collect($queries)->search(fn ($sql) => str_starts_with($sql, 'insert into "device_push_tokens"'));
    expect($lockIndex)->not->toBeFalse()->and($insertIndex)->not->toBeFalse()->and($lockIndex)->toBeLessThan($insertIndex);
});
