<?php

namespace App\DataTransferObjects;

final readonly class RefreshResult
{
    public function __construct(
        public string $command,
        public int $exitCode,
        public string $output,
    ) {}

    public function succeeded(): bool
    {
        return $this->exitCode === 0;
    }
}
