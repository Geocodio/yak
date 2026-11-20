<?php

namespace App\Enums;

enum ThreadAgentStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Stopped = 'stopped';

    /** The status the CLI reports in task_notification; an unknown one counts as failed. */
    public static function fromNotification(?string $status): self
    {
        return match ($status) {
            'completed' => self::Completed,
            'stopped', 'killed' => self::Stopped,
            default => self::Failed,
        };
    }
}
