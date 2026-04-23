<?php

namespace App\Exceptions;

use RuntimeException;

class DeploymentStartTimeoutException extends RuntimeException
{
    public function __construct(public readonly string $phase, string $message = '')
    {
        parent::__construct($message ?: "Deployment start timed out during phase: {$phase}");
    }
}
