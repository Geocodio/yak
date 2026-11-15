<?php

use App\Models\DevicePushToken;
use App\Services\Push\Apns\ApnsClient;
use App\Services\Push\Apns\ApnsProviderToken;
use App\Services\Push\Apns\ApnsResponse;
use App\Services\Push\Apns\ApnsTokenFailures;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00', 'UTC'));
});

function bearerOf(Request $request): string
{
    return substr($request->header('Authorization')[0], strlen('Bearer '));
}

it('sends an alert with the headers Apple needs', function () {
    fakeApns();
    $token = str_repeat('a1', 32);

    $response = app(ApnsClient::class)->sendAlert($token, ['aps' => ['alert' => 'Hi']], 'collapse-1');

    $request = apnsRequests("/3/device/{$token}")[0];
    expect($response->isSuccess())->toBeTrue()
        ->and($request->url())->toBe("https://api.push.apple.com/3/device/{$token}")
        ->and($request->method())->toBe('POST')
        ->and($request->header('apns-push-type'))->toBe(['alert'])
        ->and($request->header('apns-topic'))->toBe(['com.example.yak'])
        ->and($request->header('apns-priority'))->toBe(['10'])
        ->and($request->header('apns-expiration'))->toBe([(string) CarbonImmutable::parse('2026-11-17 12:00:00', 'UTC')->getTimestamp()])
        ->and($request->header('apns-collapse-id'))->toBe(['collapse-1'])
        ->and(count(explode('.', bearerOf($request))))->toBe(3)
        ->and($request->data())->toBe(['aps' => ['alert' => 'Hi']]);
});

it('uses the sandbox host for a sandbox instance', function () {
    fakeApns(environment: 'sandbox');

    app(ApnsClient::class)->sendAlert(str_repeat('a1', 32), [], 'c');

    expect(apnsRequests('api.sandbox.push.apple.com/3/device/'))->toHaveCount(1);
});

it('sends a start push to the live activity topic', function () {
    fakeApns();

    app(ApnsClient::class)->sendLiveActivityStart(str_repeat('b2', 32), ['aps' => ['event' => 'start']]);

    $request = apnsRequests('/3/device/')[0];
    expect($request->header('apns-push-type'))->toBe(['liveactivity'])
        ->and($request->header('apns-topic'))->toBe(['com.example.yak.push-type.liveactivity'])
        ->and($request->header('apns-priority'))->toBe(['10']);
});

it('broadcasts to a channel with the given priority', function () {
    fakeApns();

    app(ApnsClient::class)->broadcast('dGVzdC1jaGFubmVs', ['aps' => ['event' => 'update']], 5);

    $request = apnsRequests('/4/broadcasts/apps/com.example.yak')[0];
    expect($request->url())->toBe('https://api.push.apple.com/4/broadcasts/apps/com.example.yak')
        ->and($request->header('apns-channel-id'))->toBe(['dGVzdC1jaGFubmVs'])
        ->and($request->header('apns-push-type'))->toBe(['liveactivity'])
        ->and($request->header('apns-priority'))->toBe(['5']);
});

it('creates a channel and reads its id', function () {
    fakeApns();

    $response = app(ApnsClient::class)->createChannel();

    $request = apnsRequests('/1/apps/com.example.yak/channels')[0];
    expect($response->channelId)->toBe('dGVzdC1jaGFubmVs')
        ->and($request->url())->toBe('https://api-manage-broadcast.push.apple.com:2196/1/apps/com.example.yak/channels')
        ->and($request->data())->toBe(['message-storage-policy' => 1, 'push-type' => 'LiveActivity']);
});

it('deletes a channel and treats a missing channel as deleted', function () {
    fakeApns(['api-manage-broadcast.push.apple.com:2196/*' => Http::response(['reason' => 'ChannelNotFound'], 404)]);

    $response = app(ApnsClient::class)->deleteChannel('dGVzdC1jaGFubmVs');

    $request = apnsRequests('/channels')[0];
    expect($request->method())->toBe('DELETE')
        ->and($request->header('apns-channel-id'))->toBe(['dGVzdC1jaGFubmVs'])
        ->and($response->isSuccess())->toBeTrue();
});

it('answers a timeout as retryable', function () {
    fakeApns(['api.push.apple.com/*' => fn () => throw new ConnectionException('cURL error 28: timed out for /3/device/secret')]);

    $response = app(ApnsClient::class)->sendAlert(str_repeat('a1', 32), [], 'c');

    expect($response->status)->toBe(0)
        ->and($response->reason)->toBe('ConnectionException')
        ->and($response->isRetryable())->toBeTrue()
        ->and($response->isSuccess())->toBeFalse();
});

it('renews the provider token once', function () {
    fakeApns(['api.push.apple.com/*' => Http::sequence()
        ->push(['reason' => 'ExpiredProviderToken'], 403)
        ->push('', 200)]);

    $response = app(ApnsClient::class)->sendAlert(str_repeat('a1', 32), [], 'c');

    $requests = apnsRequests('/3/device/');
    expect($response->isSuccess())->toBeTrue()
        ->and($requests)->toHaveCount(2)
        ->and(bearerOf($requests[0]))->not->toBe(bearerOf($requests[1]));
});

it('returns a second provider token refusal without a third request', function () {
    fakeApns(['api.push.apple.com/*' => Http::response(['reason' => 'InvalidProviderToken'], 403)]);

    $response = app(ApnsClient::class)->sendAlert(str_repeat('a1', 32), [], 'c');

    expect($response->isProviderTokenProblem())->toBeTrue()
        ->and(apnsRequests('/3/device/'))->toHaveCount(2);
});

it('renews without repeating a channel creation when asked', function () {
    fakeApns(['api-manage-broadcast.push.apple.com:2196/*' => Http::response(['reason' => 'ExpiredProviderToken'], 403)]);
    $before = app(ApnsProviderToken::class)->current();
    $this->travel(1)->seconds();

    $response = app(ApnsClient::class)->createChannel(false);

    expect($response->status)->toBe(403)
        ->and(apnsRequests('/channels'))->toHaveCount(1)
        ->and(Cache::get('apns:provider-token:ABC123DEFG'))->not->toBe($before);
});

it('classifies Apple answers', function (int $status, ?string $reason, array $expected) {
    $response = new ApnsResponse($status, $reason);

    expect([
        'gone' => $response->isTokenGone(),
        'provider' => $response->isProviderTokenProblem(),
        'retryable' => $response->isRetryable(),
        'success' => $response->isSuccess(),
    ])->toBe($expected);
})->with([
    'bad device token' => [400, 'BadDeviceToken', ['gone' => true, 'provider' => false, 'retryable' => false, 'success' => false]],
    'wrong topic' => [400, 'DeviceTokenNotForTopic', ['gone' => true, 'provider' => false, 'retryable' => false, 'success' => false]],
    'unregistered' => [410, 'Unregistered', ['gone' => true, 'provider' => false, 'retryable' => false, 'success' => false]],
    'invalid provider token' => [403, 'InvalidProviderToken', ['gone' => false, 'provider' => true, 'retryable' => false, 'success' => false]],
    'too many requests' => [429, 'TooManyRequests', ['gone' => false, 'provider' => false, 'retryable' => true, 'success' => false]],
    'server error' => [500, null, ['gone' => false, 'provider' => false, 'retryable' => true, 'success' => false]],
    'unavailable' => [503, 'ServiceUnavailable', ['gone' => false, 'provider' => false, 'retryable' => true, 'success' => false]],
    'payload too large' => [400, 'PayloadTooLarge', ['gone' => false, 'provider' => false, 'retryable' => false, 'success' => false]],
    'ok' => [200, null, ['gone' => false, 'provider' => false, 'retryable' => false, 'success' => true]],
]);

it('never logs a device token', function (string $kind) {
    Log::spy();
    Exceptions::fake();
    $token = str_repeat('c3', 32);
    fakeApns(['api.push.apple.com/*' => match ($kind) {
        'bad token' => Http::response(['reason' => 'BadDeviceToken'], 400),
        'timeout' => fn () => throw new ConnectionException("cURL error 28 for https://api.push.apple.com/3/device/{$token}"),
        'server error' => Http::response('', 500),
    }]);
    $row = DevicePushToken::factory()->create(['token' => $token]);

    $response = app(ApnsClient::class)->sendAlert($token, [], 'c');
    app(ApnsTokenFailures::class)->record($row, $response);

    expect($response)->toBeInstanceOf(ApnsResponse::class);
    Log::shouldNotHaveReceived('error');
    Log::shouldNotHaveReceived('warning');
    Exceptions::assertNotReported(fn (Throwable $exception) => str_contains($exception->getMessage(), $token));
    $stored = DevicePushToken::query()->find($row->id)?->last_error ?? '';
    expect($stored)->not->toContain($token);
})->with(['bad token', 'timeout', 'server error']);

it('deletes a gone token row and stores a refusal on a kept one', function () {
    Exceptions::fake();
    $gone = DevicePushToken::factory()->create();
    $kept = DevicePushToken::factory()->create();
    $sent = DevicePushToken::factory()->create(['last_error' => '400 PayloadTooLarge']);

    app(ApnsTokenFailures::class)->record($gone, new ApnsResponse(410, 'Unregistered'));
    app(ApnsTokenFailures::class)->record($kept, new ApnsResponse(400, 'PayloadTooLarge'));
    app(ApnsTokenFailures::class)->record($sent, new ApnsResponse(200));

    expect(DevicePushToken::query()->find($gone->id))->toBeNull()
        ->and($kept->fresh()->last_error)->toBe('400 PayloadTooLarge')
        ->and($sent->fresh()->last_error)->toBeNull()
        ->and($sent->fresh()->last_sent_at)->not->toBeNull();
    Exceptions::assertReportedCount(1);
});
