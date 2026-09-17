<?php

namespace App\Events;

use App\Enums\TaskStatus;
use App\Models\YakTask;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired from YakTask::booted() whenever `status` changes through
 * Eloquent, and explicitly from the two raw-query writers in
 * TaskActionController. One place to hook the ~15 sites that move a
 * task through its state machine.
 */
class TaskStatusChanged
{
    use Dispatchable;

    public function __construct(
        public readonly YakTask $task,
        public readonly ?TaskStatus $from,
        public readonly TaskStatus $to,
    ) {}
}
