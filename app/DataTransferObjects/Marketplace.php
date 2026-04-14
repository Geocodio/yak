<?php

namespace App\DataTransferObjects;

use Carbon\Carbon;

final readonly class Marketplace
{
    public function __construct(
        public string $name,
        public string $source,
        public ?Carbon $lastUpdated,
    ) {}
}
