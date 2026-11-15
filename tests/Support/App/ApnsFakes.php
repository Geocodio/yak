<?php

use App\Enums\PushTokenKind;
use App\Models\Device;
use App\Models\DevicePushToken;
use App\Models\User;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Points the APNs config at a generated P-256 key file and fakes Apple's hosts.
 *
 * `$responses` are checked before the defaults. Apple's hosts are matched by name only, so a test that asks for a request to any other host still gets the normal `Http` behavior unless `$preventStrayRequests` is true.
 *
 * @param  array<string, mixed>  $responses
 * @return string the path of the key file
 */
function fakeApns(array $responses = [], string $environment = 'production', bool $preventStrayRequests = true): string
{
    $path = tempnam(sys_get_temp_dir(), 'yak-apns-');
    $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]) ?: throw new RuntimeException('Could not generate a test key.');
    openssl_pkey_export($key, $pem);
    file_put_contents($path, $pem);
    register_shutdown_function(static fn () => @unlink($path));

    config([
        'yak.apns' => [
            'key_path' => $path,
            'key_id' => 'ABC123DEFG',
            'team_id' => 'TEAM123456',
            'bundle_id' => 'com.example.yak',
            'environment' => $environment,
        ],
        'app.url' => 'https://yak.example.com',
    ]);

    if ($preventStrayRequests) {
        Http::preventStrayRequests();
    }

    $channelAnswer = fn (Request $request) => $request->method() === 'DELETE'
        ? Http::response('', 204)
        : Http::response('', 201, ['apns-channel-id' => 'dGVzdC1jaGFubmVs']);

    Http::fake($responses + [
        'api.push.apple.com/*' => Http::response('', 200),
        'api.sandbox.push.apple.com/*' => Http::response('', 200),
        'api-manage-broadcast.push.apple.com:2196/*' => $channelAnswer,
        'api-manage-broadcast.sandbox.push.apple.com:2195/*' => $channelAnswer,
    ]);

    return $path;
}

/**
 * The requests recorded by `Http::fake()` whose URL contains `$pathFragment`.
 *
 * @return list<Request>
 */
function apnsRequests(string $pathFragment): array
{
    $matching = [];

    foreach (Http::recorded() as [$request]) {
        if (str_contains($request->url(), $pathFragment)) {
            $matching[] = $request;
        }
    }

    return $matching;
}

/**
 * A phone device ("Test phone") for the user with its `alert` token.
 */
function alertTokenFor(User $user, ?string $token = null): DevicePushToken
{
    $device = Device::factory()->phone()->forUser($user)->create(['name' => 'Test phone']);

    return DevicePushToken::factory()->create([
        'device_id' => $device->id,
        'kind' => PushTokenKind::Alert,
        'token' => $token ?? str_repeat('a1', 32),
        'environment' => 'production',
    ]);
}
