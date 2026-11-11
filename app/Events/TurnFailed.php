<?php

namespace App\Events;

use App\Models\TaskRun;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A turn ended without finishing. A turn the runaway guard stopped also fires
 * this with turn_state StoppedByGuard; the after-turn listener skips such
 * turns, because the guard already told the source and the person decides
 * whether to continue.
 */
class TurnFailed
{
    use Dispatchable;

    public function __construct(public readonly TaskRun $run) {}
}
