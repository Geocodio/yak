<?php

namespace App\Http\Resources;

use App\Enums\ThreadListFilter;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\NeedsYouCount;
use App\Services\Threads\NeedsYouTasks;
use App\Services\Threads\ThreadList;

/**
 * The app's thread list: the web list with a `type` on every root row and the
 * needs-you tasks merged into `needsYou`.
 */
final class AppThreadListData
{
    /**
     * @param  list<string>  $sources
     * @param  list<string>  $starters
     * @return array{starred: list<array<string, mixed>>, needsYou: list<array<string, mixed>>, threads: list<array<string, mixed>>, needsYouCount: int, hasAnyThreads: bool, facets: array{sources: list<array{key: string, label: string, count: int}>, starters: list<array{key: string, name: string, avatarUrl: ?string, count: int}>}}
     */
    public static function for(User $user, ThreadListFilter $filter, ?string $search, array $sources = [], array $starters = []): array
    {
        $list = ThreadList::for($user, $filter, $search, $sources, $starters);
        $hasListFilters = $sources !== [] || $starters !== [];
        $taskItems = $filter === ThreadListFilter::Closed || $hasListFilters ? [] : NeedsYouTasks::for($user, $search, includesCoOwnerOnOpenThread: true);
        $needsYou = [...self::typed($list['needsYou']), ...$taskItems];

        usort($needsYou, fn (array $a, array $b): int => match (true) {
            ($a['lastActivityAt'] ?? null) === ($b['lastActivityAt'] ?? null) => 0,
            ($a['lastActivityAt'] ?? null) === null => 1,
            ($b['lastActivityAt'] ?? null) === null => -1,
            default => strcmp($b['lastActivityAt'], $a['lastActivityAt']),
        });

        $isUnfilteredMine = $filter === ThreadListFilter::Mine && $search === null;

        return [
            'starred' => self::typed($list['starred']),
            'needsYou' => $needsYou,
            'threads' => self::typed($list['threads']),
            'needsYouCount' => NeedsYouCount::for($user, $isUnfilteredMine ? $list['needsYouCount'] : null),
            'hasAnyThreads' => $list['hasAnyThreads'],
            'facets' => $list['facets'],
        ];
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
