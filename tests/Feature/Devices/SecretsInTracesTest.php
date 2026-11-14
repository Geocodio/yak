<?php

use App\Http\Resources\AppDeviceData;
use App\Services\Devices\DeviceFlow;
use App\Services\Devices\DeviceTokens;
use App\Services\Devices\PairingCodes;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the tables the secret is looked up in, so the lookup throws a QueryException from under the method that holds the secret.
 */
function breakDeviceTables(): void
{
    Schema::dropIfExists('device_pairing_codes');
    Schema::dropIfExists('device_authorizations');
    Schema::dropIfExists('devices');
}

function thrownFrom(Closure $callback): Throwable
{
    try {
        $callback();
    } catch (Throwable $exception) {
        return $exception;
    }

    throw new RuntimeException('The call did not throw.');
}

it('keeps a bearer token, pairing code and device code out of exception traces', function (string $name, Closure $call) {
    $secret = 'yakdev_' . 'SECRETSECRETSECRETSECRETSECRETSECRET1234';
    breakDeviceTables();

    $exception = thrownFrom(fn () => $call($secret));

    expect($exception)->toBeInstanceOf(QueryException::class)
        ->and($exception->getTraceAsString())->not->toContain(substr($secret, 0, 12))
        ->and(json_encode(array_column($exception->getTrace(), 'args')))->not->toContain('SECRETSECRET');
})->with([
    'DeviceTokens::resolve' => ['resolve', fn (#[SensitiveParameter] string $secret) => app(DeviceTokens::class)->resolve($secret)],
    'PairingCodes::redeem' => ['redeem', fn (#[SensitiveParameter] string $secret) => app(PairingCodes::class)->redeem($secret, ['name' => 'n', 'os' => '1', 'model' => 'm', 'app_version' => '1'], null)],
    'DeviceFlow::poll' => ['poll', fn (#[SensitiveParameter] string $secret) => app(DeviceFlow::class)->poll($secret)],
]);

it('marks every secret carrying parameter as sensitive', function (string $class, string $method, string $parameter) {
    $reflection = new ReflectionParameter([$class, $method], $parameter);

    expect($reflection->getAttributes(SensitiveParameter::class))->toHaveCount(1);
})->with([
    [DeviceTokens::class, 'hash', 'token'],
    [DeviceTokens::class, 'resolve', 'bearerToken'],
    [PairingCodes::class, 'redeem', 'code'],
    [PairingCodes::class, 'qrPayload', 'code'],
    [DeviceFlow::class, 'poll', 'deviceCode'],
    [AppDeviceData::class, 'pairing', 'token'],
]);

it('writes no part of a bearer token to the log when the database is down', function () {
    $token = app(DeviceTokens::class)->generate();
    $path = tempnam(sys_get_temp_dir(), 'yaklog');
    config(['logging.default' => 'secretcheck', 'logging.channels.secretcheck' => ['driver' => 'single', 'path' => $path, 'level' => 'debug']]);
    Log::forgetChannel('secretcheck');
    breakDeviceTables();

    try {
        $this->getJson('/api/app/v1/me', ['X-Yak-Protocol' => '1', 'Authorization' => "Bearer {$token}"])->assertStatus(503);

        $log = (string) file_get_contents($path);
        expect($log)->toContain('QueryException')->not->toContain(substr($token, 0, 12));
    } finally {
        @unlink($path);
    }
});
