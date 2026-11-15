<?php

namespace App\Http\Resources;

use App\Models\CoOwnerRequest;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\GitHubWriteAccess;
use App\Services\CoOwner\TaskRequester;
use App\Support\PersonName;

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
            'fallbackName' => $fallbackName === null ? null : PersonName::first($fallbackName),
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

        // The page may have moved on (a tag, a decline or a held change) since it rendered.
        if ($viewer === null || $repository === null || ! (self::forTask($root, CoOwnerPanelData::latestRequest($root), $viewer)['canPick'] ?? false)) {
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

    /**
     * The candidates of `candidates()` with every GitHub check time bounded. No new check starts once the budget has run out; the people found so far are returned with `isComplete: false`, and so are they when a check failed (GitHub down).
     *
     * @return array{candidates: list<array{id: int, name: string}>, isComplete: bool}
     */
    public static function candidatesWithin(YakTask $root, ?User $viewer, int $budgetSeconds = 8): array
    {
        $repository = $root->repository;

        if ($viewer === null || $repository === null || ! (self::forTask($root, CoOwnerPanelData::latestRequest($root), $viewer)['canPick'] ?? false)) {
            return ['candidates' => [], 'isComplete' => true];
        }

        $writeAccess = app(GitHubWriteAccess::class);
        $deadline = now()->addSeconds($budgetSeconds);
        $candidates = [];
        $isComplete = true;

        $users = User::whereNotNull('github_id')->whereKeyNot($viewer->id)->orderBy('name')->limit(self::CANDIDATE_SCAN_LIMIT)->get();

        foreach ($users as $user) {
            if (now()->greaterThan($deadline)) {
                $isComplete = false;
                break;
            }

            $access = $writeAccess->check($repository, $user, isTimeBounded: true);

            if ($access === 'write') {
                $candidates[] = ['id' => $user->id, 'name' => $user->name];
            } elseif ($access === 'error') {
                $isComplete = false;
            }
        }

        return ['candidates' => $candidates, 'isComplete' => $isComplete];
    }

    public static function isRequester(YakTask $root, ?User $viewer): bool
    {
        return $viewer !== null && app(TaskRequester::class)->isRequester($root, $viewer, $viewer->slack_user_id);
    }
}
