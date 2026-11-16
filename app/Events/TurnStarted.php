<?php

namespace App\Events;

use App\Models\TaskRun;
use Illuminate\Foundation\Events\Dispatchable;

class TurnStarted
{
    use Dispatchable;

    public function __construct(public readonly TaskRun $run) {}
}
