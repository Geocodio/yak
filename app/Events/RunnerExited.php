<?php

namespace App\Events;

use App\Models\Thread;
use Illuminate\Foundation\Events\Dispatchable;

class RunnerExited
{
    use Dispatchable;

    public function __construct(
        public readonly Thread $thread,
        public readonly int $generation,
        public readonly ?int $exitCode,
    ) {}
}
