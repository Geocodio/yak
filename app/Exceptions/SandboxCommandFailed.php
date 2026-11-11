<?php

namespace App\Exceptions;

use Illuminate\Contracts\Process\ProcessResult;
use RuntimeException;

/**
 * An Incus command could not be carried out. The message holds the operation,
 * exit code and a stderr excerpt, never the command line, so env values passed
 * as arguments cannot leak into logs.
 */
class SandboxCommandFailed extends RuntimeException
{
    public static function for(string $operation, ProcessResult $result): self
    {
        $stderr = mb_substr(trim($result->errorOutput()), 0, 500);

        return new self("Sandbox {$operation} failed (exit {$result->exitCode()}): {$stderr}", (int) $result->exitCode());
    }
}
