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
            $channel = class_basename(static::class);
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
}
