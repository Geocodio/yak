<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A teleport rule was broken. Rendered as a CLI API error body for api/cli/* requests.
 */
class TeleportError extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 409,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }
}
