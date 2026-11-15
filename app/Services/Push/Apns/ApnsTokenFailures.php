<?php

namespace App\Services\Push\Apns;

use App\Models\DevicePushToken;
use RuntimeException;

/**
 * What an answer from Apple does to the token row it was sent to. Only the status and Apple's reason word are stored or reported, never the token.
 */
final class ApnsTokenFailures
{
    public function record(DevicePushToken $token, ApnsResponse $response): void
    {
        if ($response->isSuccess()) {
            $token->forceFill(['last_sent_at' => now(), 'last_error' => null])->save();

            return;
        }

        if ($response->isTokenGone()) {
            $token->delete();

            return;
        }

        $summary = trim("{$response->status} {$response->reason}");
        $token->forceFill(['last_error' => mb_substr($summary, 0, 255)])->save();

        report(new RuntimeException("APNs refused push token row {$token->id}: {$summary}."));
    }
}
