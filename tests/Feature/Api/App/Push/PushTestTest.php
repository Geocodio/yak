<?php

use App\Enums\PushTokenKind;
use App\Models\DevicePushToken;
use App\Services\Push\Apns\PushRefusals;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Support\ContractFixture;

const PUSH_TEST_PATH = '/api/app/v1/push-tokens/alert/test';

function phoneWithAlertToken(array $apple = []): array
{
    fakeApns($apple);
    [$device, $headers] = phoneWithHeaders();
    $token = DevicePushToken::factory()->create(['device_id' => $device->id, 'kind' => PushTokenKind::Alert, 'token' => str_repeat('ab', 32), 'environment' => 'production']);

    return [$device, $headers, $token];
}

it('sends a test notification to this device', function () {
    [$device, $headers] = phoneWithAlertToken();
    alertTokenFor($device->user, str_repeat('cd', 32));

    $response = $this->postJson(PUSH_TEST_PATH, [], $headers)->assertOk();

    ContractFixture::assertMatches('app', 'push-test', $response->json());
    $requests = apnsRequests('/3/device/');
    expect($requests)->toHaveCount(1)
        ->and($requests[0]->url())->toEndWith('/3/device/' . str_repeat('ab', 32));
    $body = json_decode($requests[0]->body(), true);
    expect($body['aps']['alert'])->toBe(['title' => 'Yak test', 'body' => 'Notifications work on this device.'])
        ->and($body['aps']['interruption-level'])->toBe('active')
        ->and($body['aps']['thread-id'])->toBe('account')
        ->and($body['yak']['event'])->toBe('test')
        ->and($body['yak']['kind'])->toBe('account')
        ->and($body['yak']['id'])->toBeNull()
        ->and($body['yak']['path'])->toBe('/settings/notifications');
    expect($requests[0]->header('apns-collapse-id')[0])->toBe(substr(hash('sha256', 'test-push'), 0, 32));
});

it('refuses without a key', function () {
    [, $headers] = phoneWithAlertToken();
    config(['yak.apns.key_path' => '']);

    $this->postJson(PUSH_TEST_PATH, [], $headers)->assertStatus(409)->assertJsonPath('error', 'push_unavailable');
    Http::assertNothingSent();
});

it('refuses a device without an alert token', function () {
    fakeApns();
    [, $headers] = phoneWithHeaders();

    $response = $this->postJson(PUSH_TEST_PATH, [], $headers)->assertStatus(409)->assertJsonPath('error', 'no_push_token');

    ContractFixture::assertMatches('app', 'error-no-push-token', $response->json());
    Http::assertNothingSent();
});

it('reports Apple\'s refusal and drops a bad token', function () {
    [, $headers, $token] = phoneWithAlertToken(['api.push.apple.com/*' => Http::response(['reason' => 'BadDeviceToken'], 400)]);

    $response = $this->postJson(PUSH_TEST_PATH, [], $headers)
        ->assertStatus(409)
        ->assertJsonPath('error', 'push_rejected')
        ->assertJsonPath('details.reason', 'BadDeviceToken')
        ->assertJsonPath('message', 'Apple does not recognize this device. Turn notifications off and on again in the app, then retry.');

    expect(DevicePushToken::query()->whereKey($token->id)->exists())->toBeFalse();
    ContractFixture::assertMatches('app', 'error-push-rejected', $response->json());
});

it('names every known reason', function () {
    $reasons = ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic', 'TopicDisallowed', 'InvalidProviderToken', 'ExpiredProviderToken', 'BadEnvironmentKeyInToken', 'BroadcastFeatureNotEnabled', 'SomethingNew'];
    $messages = array_map(fn (string $reason) => PushRefusals::rejected($reason)->getMessage(), $reasons);

    // The two provider token reasons share one sentence on purpose: the admin fixes both the same way.
    expect(array_unique($messages))->toHaveCount(count($reasons) - 1)
        ->and($messages[8])->toContain('SomethingNew');

    foreach ($messages as $message) {
        expect($message)->not->toContain('\\')->not->toContain('Exception');
    }
});

it('answers 503 when Apple is slow or down', function (Closure $apple) {
    [, $headers, $token] = phoneWithAlertToken(['api.push.apple.com/*' => $apple]);

    $this->postJson(PUSH_TEST_PATH, [], $headers)
        ->assertStatus(503)
        ->assertJsonPath('error', 'unavailable')
        ->assertJsonPath('message', 'Apple did not answer in time. Try again in a minute.');

    $row = $token->fresh();
    expect($row)->not->toBeNull()->and($row->last_error)->not->toBeNull()->and($row->last_error)->not->toContain(str_repeat('ab', 32));
})->with([
    'a timeout' => [fn () => throw new ConnectionException('cURL error 28 for https://api.push.apple.com/3/device/' . str_repeat('ab', 32))],
    'a 503' => [fn () => Http::response(['reason' => 'ServiceUnavailable'], 503)],
]);

it('limits test pushes to six a minute', function () {
    [, $headers] = phoneWithAlertToken();

    foreach (range(1, 6) as $ignored) {
        $this->postJson(PUSH_TEST_PATH, [], $headers)->assertOk();
    }

    $this->postJson(PUSH_TEST_PATH, [], $headers)->assertStatus(429)->assertJsonPath('error', 'rate_limited')->assertHeader('Retry-After');
});

it('refuses without a token, with a revoked phone and with a computer token', function () {
    fakeApns();
    $this->postJson(PUSH_TEST_PATH, [], ['X-Yak-Protocol' => '1'])->assertUnauthorized()->assertJsonPath('error', 'unauthenticated');

    [$device, $headers] = phoneWithAlertToken();
    $device->update(['revoked_at' => now()]);
    $this->postJson(PUSH_TEST_PATH, [], $headers)->assertUnauthorized()->assertJsonPath('error', 'device_revoked');

    [, $computerHeaders] = phoneWithHeaders(['user_id' => $device->user_id], isPhone: false);
    $this->postJson(PUSH_TEST_PATH, [], $computerHeaders)->assertUnauthorized()->assertJsonPath('error', 'invalid_token');
    Http::assertNothingSent();
});
