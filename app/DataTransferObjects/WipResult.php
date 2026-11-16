<?php

namespace App\DataTransferObjects;

final readonly class WipResult
{
    public function __construct(
        public bool $committed,
        public bool $pushed,
        public ?string $sha,
    ) {}
}
