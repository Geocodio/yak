<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A turn released its slot on a Claude account; waiting turns may retry.
 */
class AccountSlotFreed
{
    use Dispatchable;

    public function __construct(public readonly int $claudeAccountId) {}
}
