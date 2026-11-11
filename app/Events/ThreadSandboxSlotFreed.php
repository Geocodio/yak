<?php

namespace App\Events;

use App\Models\Thread;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A sandbox slot opened up for a thread that was waiting for one.
 */
class ThreadSandboxSlotFreed
{
    use Dispatchable;

    public function __construct(public readonly Thread $thread) {}
}
