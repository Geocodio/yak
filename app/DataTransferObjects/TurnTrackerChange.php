<?php

namespace App\DataTransferObjects;

use App\Enums\CommandState;

final readonly class TurnTrackerChange
{
    public function __construct(
        public string $uuid,
        public CommandState $state,
    ) {}
}
