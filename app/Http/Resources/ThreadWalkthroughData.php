<?php

namespace App\Http\Resources;

use App\Models\Artifact;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\VideoMetric;
use App\Services\Threads\ThreadTurns;
use App\Services\WalkthroughPrSection;
use App\Support\Tasks\VideoRenderStatus;

/**
 * The thread's latest walkthrough recording and whether one is being made.
 * The array shape below is the source of truth for `ThreadWalkthrough` in
 * `resources/js/types/threads.ts`.
 */
final class ThreadWalkthroughData
{
    public const array INTENTS = ['record_walkthrough', 'record_walkthrough_and_open_pr'];

    /**
     * Null when the thread has no recording and none is being made.
     *
     * @return ?array{isRecording: bool, isRendering: bool, video: ?array{url: string, thumbnailUrl: ?string, durationSeconds: float, chapters: list<array{title: string, startSeconds: float}>}, screenshots: list<array{url: string, caption: ?string}>, isInPullRequest: bool, isRenderFailed: bool, renderError: ?string, canRetryRender: bool}
     */
    public static function for(Thread $thread): ?array
    {
        $activeRunId = ThreadTurns::activeTurn($thread)?->id;
        $isRecording = $activeRunId !== null
            && ThreadTurnMessage::query()->where('task_run_id', $activeRunId)->whereIn('intent', self::INTENTS)->exists();
        $task = $thread->pullRequestTask();

        if ($task === null) {
            return null;
        }

        $cut = $task->artifacts()->cut()->latest('id')->first();
        $script = $task->artifacts()->role('script')->latest('id')->first();
        $screenshots = $task->artifacts()->role('screenshot')->orderBy('id')->get();

        $renderStatus = VideoRenderStatus::for($task);
        $isRenderFailed = $renderStatus->state === VideoRenderStatus::Failed;

        if ($cut === null && $script === null && $screenshots->isEmpty() && ! $isRecording && ! $isRenderFailed) {
            return null;
        }

        $thumbnail = $task->artifacts()->thumbnail()->latest('id')->first();
        $durationSeconds = (float) (VideoMetric::query()
            ->where('yak_task_id', $task->id)
            ->where('status', VideoMetric::STATUS_RENDERED)
            ->latest('id')
            ->value('duration_seconds') ?? 0.0);

        return [
            'isRecording' => $isRecording,
            // A script newer than the cut is a recording whose render has not finished.
            'isRendering' => $script !== null && ($cut === null || $script->id > $cut->id),
            'video' => $cut === null ? null : [
                'url' => $cut->signedUrl(),
                'thumbnailUrl' => $thumbnail?->publicUrl() ?? $thumbnail?->signedUrl(),
                'durationSeconds' => $durationSeconds,
                'chapters' => array_values(array_map(
                    fn (array $chapter): array => ['title' => $chapter['title'], 'startSeconds' => $chapter['startSeconds']],
                    WalkthroughPrSection::chaptersForTask($task),
                )),
            ],
            'screenshots' => array_values($screenshots->map(fn (Artifact $artifact): array => [
                'url' => $artifact->publicUrl() ?? $artifact->signedUrl(),
                'caption' => $artifact->caption,
            ])->all()),
            'isInPullRequest' => $task->pr_url !== null,
            'isRenderFailed' => $isRenderFailed,
            'renderError' => $isRenderFailed ? ($renderStatus->error ?? 'The render failed without a recorded reason.') : null,
            'canRetryRender' => $thread->isOpen() && $task->artifacts()->rawFootage()->exists(),
        ];
    }
}
