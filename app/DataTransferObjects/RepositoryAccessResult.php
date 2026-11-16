<?php

namespace App\DataTransferObjects;

final readonly class RepositoryAccessResult
{
    public function __construct(
        public bool $allowed,
        public ?string $reason = null,
    ) {}
}
