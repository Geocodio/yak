<?php

namespace App\DataTransferObjects;

use App\Models\ClaudeAccount;

/**
 * The account and model a new thread starts on. A null account means no
 * usable account exists and the thread has to be blocked.
 */
final readonly class ResolvedAccount
{
    public function __construct(
        public ?ClaudeAccount $account,
        public string $model,
    ) {}
}
