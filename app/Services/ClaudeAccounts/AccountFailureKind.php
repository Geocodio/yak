<?php

namespace App\Services\ClaudeAccounts;

use App\Enums\ThreadBlockReason;

enum AccountFailureKind: string
{
    case AuthFailed = 'auth_failed';
    case UsageLimit = 'usage_limit';
    case TransientRateLimit = 'transient_rate_limit';

    public function blockReason(): ?ThreadBlockReason
    {
        return match ($this) {
            self::AuthFailed => ThreadBlockReason::AccountInvalid,
            self::UsageLimit => ThreadBlockReason::UsageLimit,
            self::TransientRateLimit => null,
        };
    }
}
