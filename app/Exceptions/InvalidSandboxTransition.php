<?php

namespace App\Exceptions;

use App\Enums\ThreadSandboxState;
use RuntimeException;

class InvalidSandboxTransition extends RuntimeException
{
    public static function for(string $operation, ThreadSandboxState $from): self
    {
        return new self("Cannot {$operation} a sandbox that is {$from->value}.");
    }
}
