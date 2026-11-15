<?php

use App\Services\Push\Apns\PushRefusals;

it('gives every refusal a stable code and a sentence', function () {
    $unavailable = PushRefusals::unavailable();
    $noToken = PushRefusals::noToken();
    $production = PushRefusals::environmentMismatch('production', 'sandbox');
    $sandbox = PushRefusals::environmentMismatch('sandbox', 'production');

    expect([$unavailable->errorCode, $unavailable->status])->toBe(['push_unavailable', 409])
        ->and($noToken->errorCode)->toBe('no_push_token')
        ->and($production->errorCode)->toBe('push_environment_mismatch')
        ->and($production->details)->toBe(['expected' => 'production', 'given' => 'sandbox'])
        ->and($production->getMessage())->toBe('This Yak sends notifications to production builds only. Install the build from TestFlight to get notifications.')
        ->and($sandbox->getMessage())->toBe('This Yak sends notifications to development builds only. Install a development build to get notifications.');
});

it('explains each Apple reason and names an unknown one', function (string $reason, bool $isKnown) {
    $refusal = PushRefusals::rejected($reason);

    expect($refusal->errorCode)->toBe('push_rejected')
        ->and($refusal->details)->toBe(['reason' => $reason])
        ->and(str_contains($refusal->getMessage(), $reason))->toBe(! $isKnown);
})->with([
    ['BadDeviceToken', true], ['Unregistered', true], ['DeviceTokenNotForTopic', true], ['TopicDisallowed', true],
    ['InvalidProviderToken', true], ['ExpiredProviderToken', true], ['BadEnvironmentKeyInToken', true],
    ['BroadcastFeatureNotEnabled', true], ['SomethingNew', false],
]);
