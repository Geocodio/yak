<?php

namespace App\DataTransferObjects;

use App\Enums\RepositorySetupStatus;

final readonly class RepositorySetupResult
{
    public function __construct(
        public string $repo,
        public RepositorySetupStatus $status,
        public ?string $error,
        public string $output,
        public float $cloneSeconds,
    ) {}
}
