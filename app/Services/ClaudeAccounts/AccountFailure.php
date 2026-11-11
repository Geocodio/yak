<?php

namespace App\Services\ClaudeAccounts;

use Carbon\CarbonImmutable;

final readonly class AccountFailure
{
    public function __construct(
        public AccountFailureKind $kind,
        public ?CarbonImmutable $resetsAt = null,
        public ?string $rateLimitType = null,
    ) {}

    public function withRateLimit(?CarbonImmutable $resetsAt, ?string $rateLimitType): self
    {
        return new self($this->kind, $resetsAt, $rateLimitType);
    }

    public function isBlocking(): bool
    {
        return $this->kind->blockReason() !== null;
    }
}
