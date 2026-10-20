<?php

namespace App\Http\Resources;

use App\Channels\Slack\CoOwnerBlocks;
use App\Http\Controllers\Settings\GitHubAccountController;
use App\Models\CoOwnerRequest;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\GitHubWriteAccess;
use App\Services\CoOwner\TaskRequester;

/**
 * The `coOwner` prop of `Tasks/Show`: the latest co-owner request of the
 * conversation and what the viewer may do with it. The array shape below is
 * the source of truth for the TypeScript type.
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
     * @return array{
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
     *     viewer: array{isRequester: bool, isLinked: bool, login: string|null, access: string},
     *     connectUrl: string,
     *     requesterName: string,
     *     linkError: string|null,
     * }|null
     */
    public static function for(YakTask $task, User $viewer): ?array
    {
        $request = self::latestRequest($task);

        if ($request === null) {
            return null;
        }

        $request->loadMissing(['task.startedBy', 'repository.defaultResponsibleUser', 'decidedBy']);
        $heldTask = $request->task;
        $repository = $request->repository;
        $statusByPath = collect($request->changed_files)->pluck('status', 'path');
        $isLinked = $viewer->hasLinkedGitHub();
        $requester = app(TaskRequester::class)->for($heldTask);

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
            'asked' => array_values($request->askedUsers()->map(fn (User $user): string => $user->name)->all()),
            'attempts' => array_values($request->ask_attempts),
            'fallbackOwner' => $repository->defaultResponsibleUser?->name,
            'viewer' => [
                'isRequester' => $requester?->is($viewer) === true,
                'isLinked' => $isLinked,
                'login' => $viewer->github_login,
                'access' => $isLinked ? app(GitHubWriteAccess::class)->check($repository, $viewer) : 'unlinked',
            ],
            'connectUrl' => GitHubAccountController::connectUrl("/tasks/{$task->rootTask()->id}"),
            'requesterName' => $requester->name ?? $heldTask->author_name ?? 'Someone',
            'linkError' => self::linkError(),
        ];
    }

    private static function linkError(): ?string
    {
        $errors = session('errors')?->getBag('default');

        return match (true) {
            $errors?->has('github_taken') === true => 'That GitHub account is already linked to ' . $errors->first('github_taken') . '.',
            $errors?->has('github') === true => 'GitHub did not connect. Try again.',
            default => null,
        };
    }
}
