<?php

namespace App\Services\ClaudeAccounts;

use App\Enums\ThreadBlockReason;

/**
 * Recognises the stream lines that mean the thread's Claude account cannot
 * be used: a rejected credential or an exhausted plan.
 */
class AccountFailureClassifier
{
    private const USAGE_LIMIT_TEXT = '/usage limit|hit your limit|limit reached|rate.?limit/i';

    /**
     * @param  array<string, mixed>  $line
     */
    public function classify(array $line): ?ThreadBlockReason
    {
        $type = $line['type'] ?? null;

        if ($type === 'assistant') {
            return match ($line['error'] ?? null) {
                'authentication_failed' => ThreadBlockReason::InvalidToken,
                'rate_limit' => ThreadBlockReason::UsageLimit,
                default => null,
            };
        }

        if ($type !== 'result' || ($line['is_error'] ?? false) !== true) {
            return null;
        }

        return match (true) {
            ($line['api_error_status'] ?? null) === 401 => ThreadBlockReason::InvalidToken,
            ($line['api_error_status'] ?? null) === 429,
            is_string($line['result'] ?? null) && preg_match(self::USAGE_LIMIT_TEXT, $line['result']) === 1 => ThreadBlockReason::UsageLimit,
            default => null,
        };
    }
}
