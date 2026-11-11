<?php

namespace App\Events;

use App\Models\Thread;
use App\Models\ThreadDiff;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A thread's stored diff was replaced by a fresh capture.
 */
class ThreadDiffUpdated
{
    use Dispatchable;

    public function __construct(public readonly Thread $thread, public readonly ThreadDiff $diff) {}
}
