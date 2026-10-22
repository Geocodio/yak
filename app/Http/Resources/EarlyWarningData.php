<?php

namespace App\Http\Resources;

use App\Models\CoOwnerRequest;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\GitHubWriteAccess;
use App\Services\CoOwner\TaskRequester;

/**
 * The `earlyWarning` prop of `Tasks/Show` and its deferred candidate list. The
 * array shape below is the source of truth for the TypeScript type.
 *
 * @phpstan-type Notice array{
 *     areas: list<array{name: string, risk: string}>,
 *     state: 'shown'|'tagged'|'declined',
 *     taggedName: string|null,
 *     fallbackName: string|null,
 *     repoName: string,
 *     canPick: bool,
 * }
 */
final class EarlyWarningData
{
    private const int CANDIDATE_SCAN_LIMIT = 25;

    /**
     * Present while the conversation has an early warning and no co-owner request yet.
     *
     * @return Notice|null
     */
    public static function forTask(YakTask $root, ?CoOwnerRequest $coOwnerRequest, ?User $viewer): ?array
    {
        $warning = $root->early_warning;

        if ($warning === null || $coOwnerRequest !== null) {
            return null;
        }

        $repository = $root->repository;
        $taggedName = $root->taggedCoOwner?->name;
        // A deleted tagged user reads as untagged.
        $state = $warning['state'] === 'tagged' && $taggedName === null ? 'shown' : $warning['state'];
        $fallbackName = $repository?->defaultResponsibleUser?->name;

        return [
            'areas' => array_values($warning['areas']),
            'state' => $state,
            'taggedName' => $state === 'tagged' ? $taggedName : null,
            'fallbackName' => $fallbackName === null ? null : explode(' ', trim($fallbackName))[0],
            'repoName' => (string) $repository?->github_full_name,
            'canPick' => $state === 'shown' && self::isRequester($root, $viewer),
        ];
    }

    /**
     * Linked users other than the requester who can write to the repository.
     * Scans at most CANDIDATE_SCAN_LIMIT users in name order, so the cost is bounded.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function candidates(YakTask $root, ?User $viewer): array
    {
        $repository = $root->repository;

        if ($viewer === null || $repository === null) {
            return [];
        }

        $writeAccess = app(GitHubWriteAccess::class);

        $candidates = User::whereNotNull('github_id')
            ->whereKeyNot($viewer->id)
            ->orderBy('name')
            ->limit(self::CANDIDATE_SCAN_LIMIT)
            ->get()
            ->filter(fn (User $user): bool => $writeAccess->check($repository, $user) === 'write')
            ->map(fn (User $user): array => ['id' => $user->id, 'name' => $user->name])
            ->all();

        return array_values($candidates);
    }

    public static function isRequester(YakTask $root, ?User $viewer): bool
    {
        return $viewer !== null && app(TaskRequester::class)->isRequester($root, $viewer, $viewer->slack_user_id);
    }
}
