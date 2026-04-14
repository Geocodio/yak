<?php

namespace App\DataTransferObjects;

use Carbon\Carbon;

final readonly class InstalledPlugin
{
    public function __construct(
        public string $name,
        public string $marketplace,
        public string $scope,
        public string $installPath,
        public string $version,
        public ?string $gitCommitSha,
        public Carbon $installedAt,
        public ?Carbon $lastUpdated,
        public bool $enabled = true,
    ) {}

    public function key(): string
    {
        return "{$this->name}@{$this->marketplace}";
    }
}
