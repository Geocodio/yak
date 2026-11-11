<?php

namespace App\Services\ClaudeAccounts;

use Carbon\CarbonImmutable;

/**
 * Recognises the stream lines that mean the thread's Claude account cannot
 * be used. Matches on structured status fields only, never on `subtype` or
 * on message text.
 */
class AccountFailureClassifier
{
    /**
     * @param  array<string, mixed>  $line
     */
    public function classify(array $line): ?AccountFailure
    {
        $type = $line['type'] ?? null;

        if ($type === 'result' && ($line['is_error'] ?? false) !== true) {
            return null;
        }

        if ($type !== 'assistant' && $type !== 'result') {
            return null;
        }

        $status = $line['api_error_status'] ?? null;

        return match (true) {
            ($line['error'] ?? null) === 'authentication_failed', $status === 401 => new AccountFailure(AccountFailureKind::AuthFailed),
            $status === 429 && ($line['api_error'] ?? null) === 'usage_limit_reached' => new AccountFailure(AccountFailureKind::UsageLimit),
            $status === 429 && ! isset($line['api_error']) => new AccountFailure(AccountFailureKind::TransientRateLimit),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array{resetsAt: CarbonImmutable, type: ?string}|null
     */
    public static function rateLimitFrom(array $line): ?array
    {
        $info = $line['rate_limit_info'] ?? null;

        if (($line['type'] ?? null) !== 'rate_limit_event' || ! is_array($info) || ($info['status'] ?? null) !== 'rejected' || ! is_numeric($info['resetsAt'] ?? null)) {
            return null;
        }

        return [
            'resetsAt' => CarbonImmutable::createFromTimestampUTC((int) $info['resetsAt']),
            'type' => is_string($info['rateLimitType'] ?? null) ? $info['rateLimitType'] : null,
        ];
    }
}
