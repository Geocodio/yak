<?php

namespace App\Services\Devices;

use RuntimeException;

/**
 * A device flow step that cannot proceed; `errorCode` is the wire error the CLI acts on and `status` the HTTP status it is sent with.
 */
class DeviceFlowException extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status = 400)
    {
        parent::__construct($message);
    }
}
