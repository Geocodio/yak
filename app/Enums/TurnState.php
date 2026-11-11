<?php

namespace App\Enums;

enum TurnState: string
{
    case Pending = 'pending';
    case WaitingForAccount = 'waiting_for_account';
    case WaitingForSandbox = 'waiting_for_sandbox';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case StoppedByGuard = 'stopped_by_guard';
    case Interrupted = 'interrupted';
}
