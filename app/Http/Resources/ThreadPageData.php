<?php

namespace App\Http\Resources;

use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use App\Enums\RunnerState;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadKind;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Enums\ThreadStatus;
use App\Enums\ThreadVisibility;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadAccess;

/**
 * Props of the `Threads/Show` page. The array shape below is the source of
 * truth for `resources/js/types/threads.ts`.
 */
final class ThreadPageData
{
    /**
     * @return array{
     *     thread: array{
     *         id: int,
     *         name: string,
     *         title: string,
     *         repo: string,
     *         kind: value-of<ThreadKind>,
     *         status: value-of<ThreadStatus>,
     *         visibility: value-of<ThreadVisibility>,
     *         branch: ?string,
     *         sandboxState: value-of<ThreadSandboxState>,
     *         runnerState: value-of<RunnerState>,
     *         ownerSide: value-of<ThreadOwnerSide>,
     *         blockedReason: ?value-of<ThreadBlockReason>,
     *         parentThreadId: ?int,
     *         rootThreadId: ?int,
     *         lastActivityAt: ?string,
     *         repositories: list<array{repo: string, role: value-of<RepositoryRole>, setupStatus: value-of<RepositorySetupStatus>}>,
     *     },
     *     canDrive: bool,
     * }
     */
    public static function build(Thread $thread, ?User $viewer): array
    {
        $repositories = [];

        foreach ($thread->repositories as $repository) {
            $repositories[] = [
                'repo' => $repository->repo,
                'role' => $repository->role->value,
                'setupStatus' => $repository->setup_status->value,
            ];
        }

        return [
            'thread' => [
                'id' => $thread->id,
                'name' => $thread->name,
                'title' => $thread->title,
                'repo' => $thread->repo,
                'kind' => $thread->kind->value,
                'status' => $thread->status->value,
                'visibility' => $thread->effectiveVisibility()->value,
                'branch' => $thread->branch,
                'sandboxState' => $thread->sandbox_state->value,
                'runnerState' => $thread->runner_state->value,
                'ownerSide' => $thread->owner_side->value,
                'blockedReason' => $thread->blocked_reason?->value,
                'parentThreadId' => $thread->parent_thread_id,
                'rootThreadId' => $thread->root_thread_id,
                'lastActivityAt' => $thread->last_activity_at?->toIso8601String(),
                'repositories' => $repositories,
            ],
            'canDrive' => app(ThreadAccess::class)->canDrive($viewer, $thread),
        ];
    }
}
