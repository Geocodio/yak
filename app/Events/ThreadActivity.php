<?php

namespace App\Events;

use App\Models\Thread;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * One handled runner or broker line, already redacted. A plain event: the
 * realtime layer listens and broadcasts.
 */
class ThreadActivity
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $line
     */
    public function __construct(
        public readonly Thread $thread,
        public readonly int $generation,
        public readonly int $sequence,
        public readonly array $line,
    ) {}
}
