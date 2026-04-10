<?php

namespace App\Http\Concerns;

use Illuminate\Http\Request;
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

        $expected = $prefix.hash_hmac($algorithm, $payload ?? $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            throw new AccessDeniedHttpException('Invalid webhook signature.');
        }
    }
}
