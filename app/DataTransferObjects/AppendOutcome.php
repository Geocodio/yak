<?php

namespace App\DataTransferObjects;

final readonly class AppendOutcome
{
    public function __construct(
        public int $status,
        public ?string $reason,
        public int $length,
        public bool $duplicate = false,
    ) {}

    public static function accepted(int $length, bool $duplicate = false): self
    {
        return new self(200, null, $length, $duplicate);
    }

    public static function rejected(string $reason, int $length): self
    {
        return new self(409, $reason, $length);
    }
}
