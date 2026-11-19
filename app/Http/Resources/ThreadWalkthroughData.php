<?php

namespace App\Http\Resources;

use App\Models\Artifact;
use App\Models\Thread;
use App\Models\ThreadEvent;
use App\Models\ThreadFileEdit;
use App\Models\ThreadTurnMessage;
use App\Models\ThreadWalkthrough;
use App\Models\VideoMetric;
use App\Services\Threads\ThreadTurns;
use App\Services\Threads\ThreadWalkthroughs;
use App\Services\WalkthroughPrSection;
use App\Support\Tasks\VideoRenderStatus;

/**
 * The thread's walkthrough recordings and whether one is being made. The
 * array shapes below are the source of truth for `ThreadWalkthrough` and
 * `EarlierWalkthrough` in `resources/js/types/threads.ts`.
 */
final class ThreadWalkthroughData
{
    public const array INTENTS = ['record_walkthrough', 'record_walkthrough_and_open_pr'];

    /** Claude's tools that write to a file. */
    private const array EDIT_TOOLS = ['Edit', 'MultiEdit', 'Write', 'NotebookEdit'];

    /**
     * Null when the thread has no recording and none is being made.
     *
     * @return ?array{number: int, nextNumber: int, anchorSequence: ?int, recordedAt: ?string, isOutdated: bool, isRecording: bool, isRendering: bool, video: ?array{url: string, thumbnailUrl: ?string, durationSeconds: float, chapters: list<array{title: string, startSeconds: float}>}, screenshots: list<array{url: string, caption: ?string}>, isInPullRequest: bool, isRenderFailed: bool, renderError: ?string, canRetryRender: bool}
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

        // A script newer than the cut is a recording whose render has not finished, so the cut shows the version before it.
        $currentCut = $cut !== null && ($script === null || $script->id < $cut->id) ? $cut : null;
        $walkthroughs = app(ThreadWalkthroughs::class);
        $current = $walkthroughs->current($thread);
        $recordedAt = $current->created_at ?? ($script ?? $cut ?? $screenshots->first())?->created_at;
        $anchorSequence = $current !== null ? $current->anchor_sequence : $walkthroughs->sequenceAt($thread, $recordedAt);
        $hasRecording = $current !== null || $cut !== null || $script !== null || $screenshots->isNotEmpty();
        $number = $current->number ?? $walkthroughs->nextNumber($thread);

        $thumbnail = $task->artifacts()->thumbnail()->latest('id')->first();
        $durationSeconds = (float) (VideoMetric::query()
            ->where('yak_task_id', $task->id)
            ->where('status', VideoMetric::STATUS_RENDERED)
            ->latest('id')
            ->value('duration_seconds') ?? 0.0);

        return [
            'number' => $number,
            'nextNumber' => $hasRecording ? $number + 1 : $number,
            'anchorSequence' => $anchorSequence,
            'recordedAt' => $recordedAt?->toIso8601String(),
            'isOutdated' => $hasRecording && self::changedSince($thread, $anchorSequence, $recordedAt),
            'isRecording' => $isRecording,
            'isRendering' => ! $isRenderFailed && $script !== null && $currentCut === null,
            'video' => $currentCut === null ? null : [
                'url' => $currentCut->signedUrl(),
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

    /**
     * The archived versions, oldest first.
     *
     * @return list<array{number: int, anchorSequence: ?int, recordedAt: ?string, video: ?array{url: string, thumbnailUrl: ?string, durationSeconds: float, chapters: list<array{title: string, startSeconds: float}>}, screenshots: list<array{url: string, caption: ?string}>}>
     */
    public static function earlier(Thread $thread): array
    {
        $walkthroughs = ThreadWalkthrough::query()->where('thread_id', $thread->id)->whereNotNull('archived_at')->with('artifacts')->orderBy('number')->get();

        return array_values($walkthroughs->map(function (ThreadWalkthrough $walkthrough): array {
            $cut = $walkthrough->artifacts->firstWhere('role', 'archived_cut');
            $thumbnail = $walkthrough->artifacts->firstWhere('role', 'archived_thumbnail');

            return [
                'number' => $walkthrough->number,
                'anchorSequence' => $walkthrough->anchor_sequence,
                'recordedAt' => $walkthrough->created_at?->toIso8601String(),
                'video' => $cut === null ? null : [
                    'url' => $cut->signedUrl(),
                    'thumbnailUrl' => $thumbnail?->signedUrl(),
                    'durationSeconds' => (float) $walkthrough->duration_seconds,
                    'chapters' => $walkthrough->chapters ?? [],
                ],
                'screenshots' => array_values($walkthrough->artifacts->where('role', 'archived_screenshot')->map(fn (Artifact $artifact): array => [
                    'url' => $artifact->signedUrl(),
                    'caption' => $artifact->caption,
                ])->all()),
            ];
        })->all());
    }

    /**
     * Whether Claude or a person edited a file after the recording was made.
     */
    private static function changedSince(Thread $thread, ?int $anchorSequence, ?\DateTimeInterface $recordedAt): bool
    {
        if ($anchorSequence === null || $recordedAt === null) {
            return false;
        }

        $hasEditEvent = ThreadEvent::query()
            ->where('thread_id', $thread->id)
            ->where('sequence', '>', $anchorSequence)
            ->where(function ($query): void {
                foreach (self::EDIT_TOOLS as $tool) {
                    $query->orWhere('payload', 'like', '%"name":"' . $tool . '"%');
                }
            })
            ->exists();

        return $hasEditEvent || ThreadFileEdit::query()->where('thread_id', $thread->id)->where('created_at', '>', $recordedAt)->exists();
    }
}
