<?php

namespace App\Events;

use App\Models\TaskRun;
use Illuminate\Foundation\Events\Dispatchable;

class TurnCompleted
{
    use Dispatchable;

    public function __construct(public readonly TaskRun $run) {}
}
