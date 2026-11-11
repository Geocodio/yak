<?php

namespace App\Services\Devices;

use RuntimeException;

/**
 * A device flow step that cannot proceed; `errorCode` is the wire error the CLI acts on.
 */
class DeviceFlowException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }
}
