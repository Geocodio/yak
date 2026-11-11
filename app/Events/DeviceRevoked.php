<?php

namespace App\Events;

use App\Models\Device;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Dispatched when a device is revoked; handedBackThreadIds are the threads Yak took back.
 */
class DeviceRevoked
{
    use Dispatchable;

    /**
     * @param  list<int>  $handedBackThreadIds
     */
    public function __construct(public Device $device, public array $handedBackThreadIds = []) {}
}
