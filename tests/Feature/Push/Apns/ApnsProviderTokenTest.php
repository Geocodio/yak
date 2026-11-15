<?php

use App\Services\Push\Apns\ApnsProviderToken;
use Carbon\CarbonImmutable;

function decodeJwtPart(string $part): array
{
    return json_decode(base64_decode(strtr($part, '-_', '+/')), true, flags: JSON_THROW_ON_ERROR);
}

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 12:00:00', 'UTC'));
});

it('signs an ES256 token Apple can verify', function () {
    $path = fakeApns();

    $token = app(ApnsProviderToken::class)->current();
    [$header, $claims, $signature] = explode('.', $token);
    $raw = base64_decode(strtr($signature, '-_', '+/'));
    $publicKey = openssl_pkey_get_details(openssl_pkey_get_private(file_get_contents($path)))['key'];

    expect(decodeJwtPart($header))->toBe(['alg' => 'ES256', 'kid' => 'ABC123DEFG'])
        ->and(decodeJwtPart($claims))->toBe(['iss' => 'TEAM123456', 'iat' => 1794830400])
        ->and(strlen($raw))->toBe(64)
        ->and(openssl_verify("{$header}.{$claims}", ApnsProviderToken::rawToDer($raw), $publicKey, OPENSSL_ALGO_SHA256))->toBe(1);
});

it('pads each half of the signature to 32 bytes', function () {
    $der = "\x30\x08\x02\x02\x00\x80\x02\x02\x01\x02";

    $raw = ApnsProviderToken::derToRaw($der);

    expect($raw)->toBe(str_pad("\x80", 32, "\x00", STR_PAD_LEFT) . str_pad("\x01\x02", 32, "\x00", STR_PAD_LEFT));
});

it('reuses the token for 50 minutes', function () {
    fakeApns();
    $first = app(ApnsProviderToken::class)->current();

    $this->travelTo(CarbonImmutable::parse('2026-11-16 12:49:00', 'UTC'));
    expect(app(ApnsProviderToken::class)->current())->toBe($first);

    $this->travelTo(CarbonImmutable::parse('2026-11-16 12:51:00', 'UTC'));
    expect(app(ApnsProviderToken::class)->current())->not->toBe($first);
});

it('signs again on renew', function () {
    fakeApns();
    $old = app(ApnsProviderToken::class)->current();

    $new = app(ApnsProviderToken::class)->renew($old);

    expect($new)->not->toBe($old)->and(app(ApnsProviderToken::class)->current())->toBe($new);
});

it('signs once for two renewals of the same rejected token', function () {
    fakeApns();
    $old = app(ApnsProviderToken::class)->current();

    $first = app(ApnsProviderToken::class)->renew($old);
    $this->travel(1)->seconds();
    $second = app(ApnsProviderToken::class)->renew($old);

    expect($second)->toBe($first);
});

it('returns the cached token when the renewal lock is held', function () {
    // The lock wait measures time with the real clock, so a frozen clock would never end it.
    $this->travelBack();
    fakeApns();
    $old = app(ApnsProviderToken::class)->current();
    $lock = Cache::lock('apns:provider-token-renewal:ABC123DEFG', 10);
    $lock->get();

    $started = microtime(true);
    $result = app(ApnsProviderToken::class)->renew($old);
    $lock->release();

    expect($result)->toBe($old)->and(microtime(true) - $started)->toBeLessThan(8);
});

it('never puts the key in an exception', function () {
    $path = fakeApns();
    $keyBytes = file_get_contents($path);
    unlink($path);

    try {
        app(ApnsProviderToken::class)->current();
        $this->fail('Expected a RuntimeException.');
    } catch (RuntimeException $exception) {
        $dump = $exception->getMessage() . $exception->getTraceAsString();
        expect($exception->getMessage())->toBe('APNs is not configured.')
            ->and($dump)->not->toContain('PRIVATE KEY')
            ->and($dump)->not->toContain(trim(explode("\n", $keyBytes)[1]));
    }
});
