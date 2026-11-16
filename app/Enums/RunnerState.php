<?php

namespace App\Enums;

enum RunnerState: string
{
    case None = 'none';
    case Starting = 'starting';
    case Idle = 'idle';
    case Busy = 'busy';
    case Stopping = 'stopping';
}
