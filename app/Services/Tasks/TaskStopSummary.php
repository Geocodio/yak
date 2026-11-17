<?php

namespace App\Services\Tasks;

use App\Enums\StopKind;
use App\Enums\TaskStatus;
use App\Models\YakTask;
use Illuminate\Support\Str;

/**
 * Why a task stopped, in plain words, with the technical text kept apart.
 * A task waiting on repository setup reads as paused whatever kind was stored.
 */
final class TaskStopSummary
{
    /**
     * @return array{kind: string, summary: string, detail: string|null, nextStep: string, isWaitingForSetup: bool}|null
     */
    public static function for(YakTask $task): ?array
    {
        $isWaitingForSetup = $task->resume_after_setup_at !== null;

        if (! in_array($task->status, [TaskStatus::Failed, TaskStatus::Expired], true)
            || ($task->stop_kind === null && ! $isWaitingForSetup)) {
            return null;
        }

        $kind = $isWaitingForSetup ? StopKind::YakRetrying : ($task->stop_kind ?? StopKind::YakStopped);

        return [
            'kind' => $kind->value,
            'summary' => $task->stop_summary ?: "Yak is waiting for this repository's setup to finish.",
            'detail' => $task->error_log !== null ? Str::limit($task->error_log, 2000) : null,
            'nextStep' => self::nextStep($kind, $task->source),
            'isWaitingForSetup' => $isWaitingForSetup,
        ];
    }

    private static function nextStep(StopKind $kind, ?string $source): string
    {
        return match (true) {
            $kind === StopKind::YakRetrying => 'Yak picks it back up on its own. Nothing needed from you.',
            $kind === StopKind::NeedsYou && ! in_array($source, ['linear', 'slack'], true) => 'Write your answer below and send it. Yak tries again with it.',
            $source === 'linear' => 'Reply in the Linear issue, or press Retry, and Yak will try again.',
            $source === 'slack' => 'Reply in the Slack thread, or press Retry, and Yak will try again.',
            default => 'Press Retry and Yak will try again.',
        };
    }
}
