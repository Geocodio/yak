<?php

namespace App\DataTransferObjects;

use App\Models\ClaudeAccount;

final readonly class ResolvedAccount
{
    public function __construct(
        public ?ClaudeAccount $account,
        public string $model,
    ) {}
}
