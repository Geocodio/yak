<?php

namespace App\Enums;

enum TurnState: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case StoppedByGuard = 'stopped_by_guard';
}
