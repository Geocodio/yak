<?php

namespace App\Services\Push\Apns;

use App\Services\Devices\ShortLockWait;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * The ES256 provider token Apple wants in the `authorization` header, reused for 50 minutes (Apple accepts a token for up to an hour and refuses one renewed more than once every 20 minutes).
 */
final class ApnsProviderToken
{
    private const REUSE_MINUTES = 50;

    public function __construct(private readonly ApnsConfig $config) {}

    public function current(): string
    {
        return Cache::get($this->cacheKey()) ?? $this->sign();
    }

    /**
     * Replaces the token Apple rejected. Several workers can hit the rejection together; only the first signs, the others receive its token.
     */
    public function renew(#[\SensitiveParameter] string $rejectedToken): string
    {
        try {
            return Cache::lock("apns:provider-token-renewal:{$this->config->keyId()}", 10)
                ->block(ShortLockWait::LOCK_WAIT_SECONDS, function () use ($rejectedToken): string {
                    $cached = Cache::get($this->cacheKey());

                    return is_string($cached) && $cached !== $rejectedToken ? $cached : $this->sign();
                });
        } catch (LockTimeoutException) {
            return Cache::get($this->cacheKey()) ?? $rejectedToken;
        }
    }

    private function sign(): string
    {
        $header = $this->base64Url(json_encode(['alg' => 'ES256', 'kid' => $this->config->keyId()], JSON_THROW_ON_ERROR));
        $claims = $this->base64Url(json_encode(['iss' => $this->config->teamId(), 'iat' => now()->getTimestamp()], JSON_THROW_ON_ERROR));
        $signedInput = "{$header}.{$claims}";

        if (! openssl_sign($signedInput, $derSignature, $this->config->privateKey(), OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('APNs provider token could not be signed.');
        }

        $token = "{$signedInput}.{$this->base64Url(self::derToRaw($derSignature))}";
        Cache::put($this->cacheKey(), $token, now()->addMinutes(self::REUSE_MINUTES));

        return $token;
    }

    /**
     * JWS wants the signature as r and s, 32 bytes each, not as ASN.1.
     */
    public static function derToRaw(string $der): string
    {
        $offset = 2 + ((ord($der[1]) & 0x80) !== 0 ? (ord($der[1]) & 0x7F) : 0);
        $parts = [];

        for ($index = 0; $index < 2; $index++) {
            $length = ord($der[$offset + 1]);
            $integer = substr($der, $offset + 2, $length);
            $parts[] = str_pad(ltrim($integer, "\x00"), 32, "\x00", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $parts[0] . $parts[1];
    }

    /**
     * The inverse of {@see derToRaw()}, used to verify a token with `openssl_verify`.
     */
    public static function rawToDer(string $raw): string
    {
        $encode = function (string $half): string {
            $half = ltrim($half, "\x00");
            $half = ($half === '' || (ord($half[0]) & 0x80) !== 0) ? "\x00" . $half : $half;

            return "\x02" . chr(strlen($half) & 0xFF) . $half;
        };

        $body = $encode(substr($raw, 0, 32)) . $encode(substr($raw, 32, 32));

        return "\x30" . chr(strlen($body) & 0xFF) . $body;
    }

    private function cacheKey(): string
    {
        return "apns:provider-token:{$this->config->keyId()}";
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
