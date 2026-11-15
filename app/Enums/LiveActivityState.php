<?php

namespace App\Enums;

enum LiveActivityState: string
{
    case Working = 'working';
    case NeedsYou = 'needs_you';
    case Finished = 'finished';
    case Failed = 'failed';
    case Stopped = 'stopped';
}
