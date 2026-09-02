<?php

namespace App\Livewire\Tasks\Support;

use App\Enums\TaskStatus;

/**
 * Presentation helpers shared by the (still-Livewire) task detail view.
 * Split out of the deleted `App\Livewire\Tasks\TaskList` component when the
 * Tasks index moved to Inertia, so `TaskDetail`'s blade partials keep
 * working until Task 5 ports the detail page too.
 */
final class TaskStyling
{
    public static function statusBadgeClasses(TaskStatus $status): string
    {
        return match ($status) {
            TaskStatus::Pending => 'bg-[rgba(107,143,163,0.12)] text-[#6b8fa3]',
            TaskStatus::Running => 'bg-[rgba(143,179,196,0.12)] text-[#8fb3c4] animate-pulse',
            TaskStatus::AwaitingClarification => 'bg-[rgba(212,145,94,0.12)] text-[#d4915e]',
            TaskStatus::AwaitingCi => 'bg-[rgba(143,179,196,0.12)] text-[#8fb3c4]',
            TaskStatus::Retrying => 'bg-[rgba(212,145,94,0.12)] text-[#d4915e]',
            TaskStatus::Success => 'bg-[rgba(122,140,94,0.12)] text-[#7a8c5e]',
            TaskStatus::Failed => 'bg-[rgba(184,84,80,0.12)] text-[#b85450]',
            TaskStatus::Expired => 'bg-[rgba(200,184,154,0.12)] text-[#c8b89a]',
            TaskStatus::Cancelled => 'bg-[rgba(200,184,154,0.12)] text-[#c8b89a]',
        };
    }

    public static function formatDuration(?int $durationMs): string
    {
        if ($durationMs === null || $durationMs === 0) {
            return '—';
        }

        $minutes = (int) round($durationMs / 60000);

        if ($minutes < 1) {
            return '1m';
        }

        if ($minutes >= 60) {
            $hours = intdiv($minutes, 60);
            $remainingMinutes = $minutes % 60;

            return $remainingMinutes > 0 ? "{$hours}h {$remainingMinutes}m" : "{$hours}h";
        }

        return "{$minutes}m";
    }
}
