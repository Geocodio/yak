<?php

namespace App\Enums;

enum TurnMessageState: string
{
    case Sent = 'sent';
    case Queued = 'queued';
    case Started = 'started';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
