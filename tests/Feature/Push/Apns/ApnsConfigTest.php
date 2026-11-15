<?php

use App\Services\Push\Apns\ApnsConfig;
use Illuminate\Support\Facades\Exceptions;

it('is configured with a readable P-256 key and a valid environment', function () {
    fakeApns();

    $config = app(ApnsConfig::class);

    expect($config->isConfigured())->toBeTrue()
        ->and($config->environment())->toBe('production')
        ->and($config->bundleId())->toBe('com.example.yak')
        ->and($config->keyId())->toBe('ABC123DEFG')
        ->and($config->teamId())->toBe('TEAM123456')
        ->and($config->privateKey())->toBeInstanceOf(OpenSSLAsymmetricKey::class);
});

it('accepts the sandbox environment', function () {
    fakeApns(environment: 'sandbox');

    expect(app(ApnsConfig::class)->isConfigured())->toBeTrue()->and(app(ApnsConfig::class)->environment())->toBe('sandbox');
});

it('is not configured', function (Closure $break, string $reason) {
    Exceptions::fake();
    $path = fakeApns();
    $break($path);

    expect(app(ApnsConfig::class)->isConfigured())->toBeFalse();

    Exceptions::assertReported(fn (RuntimeException $exception): bool => str_contains($exception->getMessage(), $reason));
    Exceptions::assertReportedCount(1);
})->with([
    'missing key id' => [fn () => config(['yak.apns.key_id' => '']), 'incomplete'],
    'missing team id' => [fn () => config(['yak.apns.team_id' => null]), 'incomplete'],
    'missing bundle id' => [fn () => config(['yak.apns.bundle_id' => '']), 'incomplete'],
    'missing file' => [fn (string $path) => unlink($path), 'unreadable'],
    'a file with an RSA key' => [function (string $path) {
        openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
        file_put_contents($path, $pem);
    }, 'not an EC P-256 key'],
    'a file with a different curve' => [function (string $path) {
        openssl_pkey_export(openssl_pkey_new(['curve_name' => 'secp384r1', 'private_key_type' => OPENSSL_KEYTYPE_EC]), $pem);
        file_put_contents($path, $pem);
    }, 'not an EC P-256 key'],
    'a file with text that is not a key' => [fn (string $path) => file_put_contents($path, 'super secret words'), 'not an EC P-256 key'],
    'an unknown environment' => [fn () => config(['yak.apns.environment' => 'staging']), 'unknown environment'],
]);

it('does not put the file contents in the report', function () {
    Exceptions::fake();
    $path = fakeApns();
    file_put_contents($path, 'super secret words');

    app(ApnsConfig::class)->isConfigured();

    Exceptions::assertReported(fn (RuntimeException $exception): bool => ! str_contains($exception->getMessage(), 'super secret words') && ! str_contains($exception->getMessage(), $path));
});

it('is silently not configured when nothing is set', function () {
    Exceptions::fake();
    config(['yak.apns' => ['key_path' => null, 'key_id' => null, 'team_id' => null, 'bundle_id' => null, 'environment' => null]]);

    expect(app(ApnsConfig::class)->isConfigured())->toBeFalse();

    Exceptions::assertNothingReported();
});

it('refuses to hand out a key when not configured', function () {
    config(['yak.apns.key_path' => null]);

    app(ApnsConfig::class)->privateKey();
})->throws(RuntimeException::class, 'APNs is not configured.');

it('reports a broken key at most once an hour', function () {
    Exceptions::fake();
    $path = fakeApns();
    openssl_pkey_export(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]), $pem);
    file_put_contents($path, $pem);

    app(ApnsConfig::class)->isConfigured();
    app(ApnsConfig::class)->isConfigured();
    Exceptions::assertReportedCount(1);

    $this->travel(61)->minutes();
    app(ApnsConfig::class)->isConfigured();
    Exceptions::assertReportedCount(2);
});

it('defaults the environment to production', function () {
    config(['yak.apns.environment' => null]);

    expect(app(ApnsConfig::class)->environment())->toBe('production')
        ->and(config('yak.apns.environment'))->toBeNull();
});
