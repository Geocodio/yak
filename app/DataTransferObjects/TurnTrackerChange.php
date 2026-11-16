<?php

namespace App\DataTransferObjects;

use App\Enums\TurnMessageState;
use App\Services\ClaudeAccounts\AccountFailure;

final readonly class TurnTrackerChange
{
    /**
     * @param  array<string, TurnMessageState>  $uuidStates  only the UUIDs whose state moved in this call
     */
    public function __construct(
        public bool $ended = false,
        public array $uuidStates = [],
        public int $resultCount = 0,
        public ?AccountFailure $failure = null,
        public float $costDeltaUsd = 0.0,
        public bool $waitingOnBackgroundTask = false,
    ) {}

    public static function none(): self
    {
        return new self;
    }
}
