<?php

namespace App\Events;

use App\Models\Device;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired after a device is revoked and its threads are back with Yak, so teleport can cancel open handoffs.
 */
class DeviceRevoked
{
    use Dispatchable;

    /**
     * @param  array<int, int>  $handedBackThreadIds
     */
    public function __construct(public Device $device, public array $handedBackThreadIds) {}
}
