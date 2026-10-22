<?php

namespace App\Http\Resources;

use App\Channels\Slack\CoOwnerBlocks;
use App\Http\Controllers\Settings\GitHubAccountController;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\GitHubWriteAccess;
use App\Services\CoOwner\TaskRequester;

/**
 * The `coOwner` prop of `Tasks/Show`: the latest co-owner request of the
 * conversation and what the viewer may do with it. The array shape below is
 * the source of truth for the TypeScript type.
 *
 * @phpstan-type Panel array{
 *     id: int,
 *     status: string,
 *     heldAt: string,
 *     expiresAt: string,
 *     decidedAt: string|null,
 *     decidedBy: string|null,
 *     feedback: string|null,
 *     repoName: string,
 *     branch: string|null,
 *     compareUrl: string,
 *     prUrl: string|null,
 *     areas: list<array{name: string, risk: string, rationale: string, files: list<array{path: string, status: string}>}>,
 *     holdReasons: list<string>,
 *     stat: array{additions: int, deletions: int, files: int},
 *     asked: list<string>,
 *     attempts: list<array{entry: string, kind: string, outcome: string}>,
 *     fallbackOwner: string|null,
 *     viewer: array{isRequester: bool, isLinked: bool, login: string|null, access: 'write'|'read'|'unlinked'|'error'},
 *     connectUrl: string,
 *     requesterName: string,
 *     linkError: array{kind: 'taken'|'failed', code: string|null, ownerName: string|null, ownerLogin: string|null}|null,
 * }
 */
final class CoOwnerPanelData
{
    /**
     * The latest request of the task's conversation, or null.
     */
    public static function latestRequest(YakTask $task): ?CoOwnerRequest
    {
        return CoOwnerRequest::where('root_task_id', $task->rootTask()->id)->latest('id')->first();
    }

    /**
     * @return Panel|null
     */
    public static function for(YakTask $task, User $viewer): ?array
    {
        return self::forRequest(self::latestRequest($task), $viewer);
    }

    /**
     * The same prop for a request already looked up. See {@see for()} for the shape.
     *
     * @return Panel|null
     */
    public static function forRequest(?CoOwnerRequest $request, User $viewer): ?array
    {
        if ($request === null) {
            return null;
        }

        $request->loadMissing(['task.startedBy', 'repository.defaultResponsibleUser', 'decidedBy']);
        $heldTask = $request->task;
        $repository = $request->repository;
        $statusByPath = collect($request->changed_files)->pluck('status', 'path');
        $isLinked = $viewer->hasLinkedGitHub();
        $requester = $heldTask->startedBy ?? app(TaskRequester::class)->for($heldTask);

        return [
            'id' => $request->id,
            'status' => $request->status->value,
            'heldAt' => $request->held_at->toIso8601String(),
            'expiresAt' => $request->expires_at->toIso8601String(),
            'decidedAt' => $request->decided_at?->toIso8601String(),
            'decidedBy' => $request->decidedBy?->name,
            'feedback' => $request->feedback,
            'repoName' => $repository->github_full_name,
            'branch' => $heldTask->branch_name,
            'compareUrl' => CoOwnerBlocks::compareUrl($request),
            'prUrl' => $heldTask->pr_url,
            'areas' => array_values(array_map(fn (array $area): array => [
                'name' => $area['name'],
                'risk' => $area['risk'],
                'rationale' => $area['rationale'],
                'files' => array_values(array_map(fn (string $path): array => [
                    'path' => $path,
                    'status' => (string) ($statusByPath[$path] ?? 'modified'),
                ], $area['paths'])),
            ], $request->matched_areas)),
            'holdReasons' => array_values($request->hold_reasons),
            'stat' => ['additions' => $request->additions, 'deletions' => $request->deletions, 'files' => $request->file_count],
            'asked' => self::askedNames($request),
            'attempts' => array_values($request->ask_attempts),
            'fallbackOwner' => $repository->defaultResponsibleUser?->name,
            'viewer' => [
                'isRequester' => app(TaskRequester::class)->isRequester($heldTask, $viewer, $viewer->slack_user_id),
                'isLinked' => $isLinked,
                'login' => $viewer->github_login,
                'access' => self::access($request, $repository, $viewer),
            ],
            'connectUrl' => GitHubAccountController::connectUrl("/tasks/{$request->root_task_id}"),
            'requesterName' => $requester->name ?? $heldTask->author_name ?? 'Someone',
            'linkError' => self::linkError(),
        ];
    }

    /**
     * Checked only for a linked viewer of a pending request; a resolved request needs no access.
     *
     * @return 'write'|'read'|'unlinked'|'error'
     */
    private static function access(CoOwnerRequest $request, Repository $repository, User $viewer): string
    {
        if (! $viewer->hasLinkedGitHub()) {
            return 'unlinked';
        }

        if ($request->status->isResolved()) {
            return 'read';
        }

        return match (app(GitHubWriteAccess::class)->check($repository, $viewer)) {
            'write' => 'write',
            'read' => 'read',
            default => 'error',
        };
    }

    /**
     * @return array{kind: 'taken'|'failed', code: string|null, ownerName: string|null, ownerLogin: string|null}|null
     */
    private static function linkError(): ?array
    {
        $errors = session('errors')?->getBag('default');

        if ($errors?->has('github_taken') === true) {
            return ['kind' => 'taken', 'code' => null, 'ownerName' => $errors->first('github_taken'), 'ownerLogin' => $errors->first('github_taken_login') ?: null];
        }

        if ($errors?->has('github') === true) {
            return ['kind' => 'failed', 'code' => preg_replace('/^error=/', '', $errors->first('github')), 'ownerName' => null, 'ownerLogin' => null];
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function askedNames(CoOwnerRequest $request): array
    {
        $users = $request->askedUsers()->keyBy('id');

        return array_values(array_filter(array_map(fn (int $id): ?string => $users->get($id)?->name, $request->asked_user_ids)));
    }
}
