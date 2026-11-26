<?php

namespace App\DataTransferObjects;

final readonly class SandboxWake
{
    public function __construct(
        public bool $ready,
        public bool $waitingForSlot,
        public bool $restoring,
        public bool $wakingElsewhere = false,
    ) {}

    public static function ready(): self
    {
        return new self(true, false, false);
    }

    public static function waitingForSlot(): self
    {
        return new self(false, true, false);
    }

    public static function restoring(): self
    {
        return new self(false, false, true);
    }

    /**
     * Another process holds the wake lock or is starting the container; callers treat it like a restore and retry.
     */
    public static function wakingElsewhere(): self
    {
        return new self(false, false, true, true);
    }
}
