<?php

namespace App\Http\Resources;

use App\Models\YakTask;
use App\Services\Threads\ThreadAccess;
use Illuminate\Http\Request;

/**
 * Task detail for the app: the keys of {@see TaskDetailData} without the
 * activity log, plus the task's thread and whether the person may drive it.
 *
 * @phpstan-type AppTask array{
 *     task: array<string, mixed>,
 *     thread: list<array<string, mixed>>,
 *     progress: array{steps: array<int, array<string, mixed>>},
 *     coOwner: array<string, mixed>|null,
 *     earlyWarning: array<string, mixed>|null,
 *     media: array<string, mixed>|null,
 *     walkthrough: array<string, mixed>|null,
 *     deployment: array<string, mixed>|null,
 *     findings: array<string, mixed>|null,
 *     composer: array<string, mixed>|null,
 *     questions: list<array<string, mixed>>|null,
 *     actions: array{canRetry: bool, canCancel: bool, canRerunReview: bool, canRequestReview: bool, canRetryRender: bool, canReroute: bool, rerouteTargets: array<int, string>},
 *     pollInterval: int,
 *     threadId: string|null,
 *     canDrive: bool,
 * }
 */
final class AppTaskData
{
    /**
     * A follow-up task answers with its root's detail.
     *
     * @return AppTask
     */
    public static function for(YakTask $task, Request $request): array
    {
        $root = $task->rootTask();
        $detail = TaskDetailData::build($root, $request, withActivity: false, isTimeBounded: true);

        if (is_array($detail['coOwner'] ?? null)) {
            $detail['coOwner']['connectUrl'] = url($detail['coOwner']['connectUrl']);
        }

        $thread = $root->thread;

        /** @var AppTask */
        return [
            ...$detail,
            'threadId' => $thread?->public_id,
            'canDrive' => $thread === null || app(ThreadAccess::class)->canDrive($request->user(), $thread),
        ];
    }
}
