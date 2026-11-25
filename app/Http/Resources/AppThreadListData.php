<?php

namespace App\Http\Resources;

use App\Enums\ThreadListFilter;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\NeedsYouCount;
use App\Services\Threads\NeedsYouTasks;
use App\Services\Threads\ThreadList;
use Illuminate\Support\Collection;

/**
 * The app's thread list: the web list with a `type` on every root row and the
 * needs-you tasks merged into `needsYou`.
 */
final class AppThreadListData
{
    /**
     * @param  list<string>  $sources
     * @param  list<string>  $starters
     * @return array{starred: list<array<string, mixed>>, needsYou: list<array<string, mixed>>, threads: list<array<string, mixed>>, needsYouCount: int, hasAnyThreads: bool, reviewCount: int, needsGitHubLink: bool, gitHubLinkPath: string, reviewGroups?: list<array{key: string, label: string, rows: list<array<string, mixed>>}>, facets: array{sources: list<array{key: string, label: string, count: int}>, starters: list<array{key: string, name: string, avatarUrl: ?string, count: int}>}}
     */
    public static function for(User $user, ThreadListFilter $filter, ?string $search, array $sources = [], array $starters = []): array
    {
        $list = ThreadList::for($user, $filter, $search, $sources, $starters);
        $hasListFilters = $sources !== [] || $starters !== [];
        $taskItems = in_array($filter, [ThreadListFilter::Closed, ThreadListFilter::Review], true) || $hasListFilters ? [] : NeedsYouTasks::for($user, $search, includesCoOwnerOnOpenThread: true);
        $needsYou = [...self::typed($list['needsYou']), ...$taskItems];

        usort($needsYou, fn (array $a, array $b): int => match (true) {
            ($a['lastActivityAt'] ?? null) === ($b['lastActivityAt'] ?? null) => 0,
            ($a['lastActivityAt'] ?? null) === null => 1,
            ($b['lastActivityAt'] ?? null) === null => -1,
            default => strcmp($b['lastActivityAt'], $a['lastActivityAt']),
        });

        $isUnfilteredMine = $filter === ThreadListFilter::Mine && $search === null;

        $answer = [
            'starred' => self::typed($list['starred']),
            'needsYou' => $needsYou,
            'threads' => self::typed($list['threads']),
            'needsYouCount' => NeedsYouCount::for($user, $isUnfilteredMine ? $list['needsYouCount'] : null),
            'hasAnyThreads' => $list['hasAnyThreads'],
            'facets' => $list['facets'],
            'reviewCount' => $list['reviewCount'],
            'needsGitHubLink' => $user->github_login === null,
            'gitHubLinkPath' => route('profile.edit', absolute: false),
        ];

        if ($filter === ThreadListFilter::Review) {
            $answer['reviewGroups'] = self::reviewGroups($list['review'] ?? []);
        }

        return $answer;
    }

    /**
     * The four groups in the web's order, empty ones included so the keys stay stable.
     *
     * @param  array<string, mixed>  $review
     * @return list<array{key: string, label: string, rows: list<array<string, mixed>>}>
     */
    private static function reviewGroups(array $review): array
    {
        $groups = [];
        $slugs = collect($review)->except('needsGitHubLink')->flatten(1)->flatMap(fn (array $row): array => array_column($row['review']['pullRequests'] ?? [], 'repo'))->unique()->values()->all();
        $names = $slugs === [] ? collect() : Repository::query()->whereIn('slug', $slugs)->pluck('github_full_name', 'slug');

        foreach ([['back_to_you', 'backToYou', 'Back to you'], ['not_reviewed', 'notReviewed', 'Not reviewed'], ['with_yak', 'withYak', 'With Yak'], ['approved', 'approved', 'Approved by you']] as [$key, $field, $label]) {
            $groups[] = ['key' => $key, 'label' => $label, 'rows' => array_map(fn (array $row): array => self::withReviewLine($row, $names), self::typed($review[$field] ?? []))];
        }

        return $groups;
    }

    /**
     * Swaps the web's review summary for the app's per-pull-request facts.
     *
     * @param  array<string, mixed>  $row
     * @param  Collection<string, ?string>  $githubNames  GitHub names by repository slug
     * @return array<string, mixed>
     */
    private static function withReviewLine(array $row, Collection $githubNames): array
    {
        $summary = $row['review'];

        $row['review'] = [
            'pullRequests' => array_map(fn (array $pullRequest): array => [
                'number' => $pullRequest['number'],
                'url' => $pullRequest['url'],
                'repository' => $githubNames->get($pullRequest['repo']) ?: $pullRequest['repo'],
                'isDraft' => $pullRequest['isDraft'],
                'mergeability' => AppPullRequestData::mergeability($pullRequest['mergeableState']),
                'checks' => AppPullRequestData::checks($pullRequest['ciStatus']),
                'approvedByYou' => $pullRequest['approvedByViewer'],
            ], $summary['pullRequests']),
            'hasNewCommitsSinceApproval' => $summary['hasNewCommitsSinceApproval'],
            'otherApprovals' => $summary['otherApprovals'],
        ];

        return $row;
    }

    /**
     * One thread's list row for a viewer, or null when they may not see it.
     *
     * @return array<string, mixed>|null
     */
    public static function rowFor(User $user, Thread $thread): ?array
    {
        $row = ThreadList::rowFor($user, $thread);

        return $row === null ? null : self::typed([$row])[0];
    }

    /**
     * Adds the row `type`, `hasRepository` and the unread mark app builds read: they draw the finished-and-not-seen dot from the `unseen` reason.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function typed(array $rows): array
    {
        return array_map(fn (array $row): array => ['type' => 'thread'] + self::withUnseenReason($row), $rows);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private static function withUnseenReason(array $row): array
    {
        if ($row['isUnread'] === true && $row['needsYouReason'] === null) {
            $row['needsYouReason'] = 'unseen';
            $row['needsYouLabel'] = 'Finished, not seen yet';
        }

        $row['hasRepository'] = $row['repo'] !== Thread::UNKNOWN_REPOSITORY;
        $row['children'] = array_map(fn (array $child): array => self::withUnseenReason($child), $row['children']);

        return $row;
    }
}
