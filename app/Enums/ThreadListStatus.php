<?php

namespace App\Enums;

enum ThreadListStatus: string
{
    case Running = 'running';
    case Blocked = 'blocked';
    case Paused = 'paused';
    case Stopped = 'stopped';
    case WaitingForRepository = 'waiting_for_repository';
}
