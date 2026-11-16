<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * An `incus storage volume` command failed. The message holds the operation,
 * exit code and a scrubbed stderr excerpt, never the command line.
 */
class DatasetCommandFailed extends RuntimeException
{
    public static function for(string $operation, int $exitCode, string $scrubbedStderr): self
    {
        return new self("Dataset {$operation} failed (exit {$exitCode}): " . mb_substr(trim($scrubbedStderr), 0, 500), $exitCode);
    }
}
