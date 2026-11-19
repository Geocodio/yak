<?php

namespace App\Services\Tasks;

use App\DataTransferObjects\TaskStop;
use App\Enums\StopKind;
use App\Models\YakTask;
use App\Support\TaskPageUrl;

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
        $url = TaskPageUrl::for($task);
        $place = TaskPageUrl::label($task);

        $nextStep = match (true) {
            $stop->kind === StopKind::YakRetrying => self::retryingStep(),
            self::acceptsReplies($task) && $stop->kind === StopKind::YakStopped => 'Reply here and Yak will try again.' . ($url === null ? '' : " Details: {$url}"),
            self::acceptsReplies($task) && $stop->canContinueAsIs => 'Reply here to let Yak continue from where it stopped, or with a narrower scope.',
            self::acceptsReplies($task) => "Reply here with {$stop->ask} and Yak will try again.",
            default => self::pageStep($stop, $place, $url),
        };

        return "{$stop->summary}\n\n{$nextStep}";
    }

    public static function forDirectMessage(YakTask $task, TaskStop $stop): string
    {
        $url = TaskPageUrl::for($task);
        $place = TaskPageUrl::label($task);

        $nextStep = match (true) {
            $stop->kind === StopKind::YakRetrying => self::retryingStep(),
            $task->source === 'linear' && $task->external_url !== null && $stop->kind === StopKind::NeedsYou => "Reply in the Linear issue with {$stop->ask} to try again: {$task->external_url}",
            $task->source === 'linear' && $task->external_url !== null => "Reply in the Linear issue to try again: {$task->external_url}",
            default => self::pageStep($stop, $place, $url),
        };

        return "{$stop->summary}\n\n{$nextStep}";
    }

    private static function retryingStep(): string
    {
        return 'Yak picks it back up on its own. Nothing needed from you.';
    }

    private static function pageStep(TaskStop $stop, string $place, ?string $url): string
    {
        $link = $url === null ? '.' : ": {$url}";

        return $stop->kind === StopKind::NeedsYou
            ? "Write {$stop->ask} on the {$place} and Yak will try again{$link}"
            : "Press Retry on the {$place}, or write a note there, and Yak will try again{$link}";
    }
}
