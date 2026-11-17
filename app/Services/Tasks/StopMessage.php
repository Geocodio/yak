<?php

namespace App\Services\Tasks;

use App\DataTransferObjects\TaskStop;
use App\Enums\StopKind;
use App\Models\YakTask;

/**
 * Builds the verbatim text Yak sends when a task stops: the summary plus a
 * next step that fits where the reader is.
 */
class StopMessage
{
    private const array REPLY_SOURCES = ['linear', 'slack'];

    public static function acceptsReplies(YakTask $task): bool
    {
        return in_array($task->source, self::REPLY_SOURCES, true);
    }

    public static function forSourceChannel(YakTask $task, TaskStop $stop): string
    {
        $url = route('tasks.show', $task);

        $nextStep = match (true) {
            $stop->kind === StopKind::YakRetrying => self::retryingStep(),
            self::acceptsReplies($task) && $stop->kind === StopKind::YakStopped => "Reply here and Yak will try again. Details: {$url}",
            self::acceptsReplies($task) => "Reply here with {$stop->ask} and Yak will try again.",
            default => self::taskPageStep($stop, $url),
        };

        return "{$stop->summary}\n\n{$nextStep}";
    }

    public static function forDirectMessage(YakTask $task, TaskStop $stop): string
    {
        $url = route('tasks.show', $task);

        $nextStep = match (true) {
            $stop->kind === StopKind::YakRetrying => self::retryingStep(),
            $task->source === 'linear' && $task->external_url !== null => "Reply in the Linear issue to try again: {$task->external_url}",
            default => self::taskPageStep($stop, $url),
        };

        return "{$stop->summary}\n\n{$nextStep}";
    }

    private static function retryingStep(): string
    {
        return 'Yak picks it back up on its own. Nothing needed from you.';
    }

    private static function taskPageStep(TaskStop $stop, string $url): string
    {
        return $stop->kind === StopKind::NeedsYou
            ? "Add {$stop->ask} on the task page, then press Retry: {$url}"
            : "Press Retry on the task page: {$url}";
    }
}
