<?php

namespace App\Http\Concerns;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

trait VerifiesWebhookSignature
{
    /**
     * Verify the webhook signature from the request.
     *
     * @throws AccessDeniedHttpException
     */
    protected function verifyWebhookSignature(
        Request $request,
        string $secret,
        string $signatureHeader = 'X-Signature-256',
        string $algorithm = 'sha256',
        string $prefix = 'sha256=',
        ?string $payload = null,
    ): void {
        $signature = $request->header($signatureHeader, '');

        $expected = $prefix . hash_hmac($algorithm, $payload ?? $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            $channel = $this->resolveWebhookChannelKey();
            Log::channel('yak')->warning('Webhook signature verification failed', [
                'channel' => $channel,
                'path' => $request->path(),
                'signature_header' => $signatureHeader,
            ]);
            // Track failures for the health check — expires after 24h
            $key = "webhook-signature-failures:{$channel}";
            Cache::add($key, 0, now()->addDay());
            Cache::increment($key);

            throw new AccessDeniedHttpException('Invalid webhook signature.');
        }
    }

    /**
     * Resolve a stable lowercase channel key for cache/log tracking.
     * Controllers under `App\Channels\<Name>\...` use the channel name from
     * the namespace; legacy controllers at `App\Http\Controllers\Webhooks\`
     * derive the same key by stripping the `WebhookController` suffix.
     * Both forms produce e.g. "slack", "linear" — so health-check cache
     * keys stay consistent across the migration.
     */
    private function resolveWebhookChannelKey(): string
    {
        $class = static::class;

        if (preg_match('/^App\\\\Channels\\\\([A-Za-z0-9]+)\\\\/', $class, $matches)) {
            return strtolower($matches[1]);
        }

        $basename = class_basename($class);

        return strtolower((string) preg_replace('/(Webhook|Interactive)?Controller$/', '', $basename));
    }
}
