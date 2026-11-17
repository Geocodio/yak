<?php

namespace App\Enums;

enum StopKind: string
{
    case NeedsYou = 'needs_you';
    case YakRetrying = 'yak_retrying';
    case YakStopped = 'yak_stopped';

    public function headlinePrefix(): string
    {
        return match ($this) {
            self::NeedsYou => 'Yak needs you',
            self::YakRetrying => 'Paused',
            self::YakStopped => 'Stopped',
        };
    }
}
