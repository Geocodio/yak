<?php

namespace App\Events;

use App\Models\Thread;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A stack crossed a usage mark: `turns`, `hours` or `children`. Nothing is
 * paused; this only lets notification channels react.
 */
class StackThresholdReached
{
    use Dispatchable;

    public function __construct(public readonly Thread $root, public readonly string $threshold, public readonly int $mark) {}
}
