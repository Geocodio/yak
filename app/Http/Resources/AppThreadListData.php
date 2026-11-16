<?php

namespace App\Http\Resources;

use App\Enums\ThreadListFilter;
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
     * @return array{starred: list<array<string, mixed>>, needsYou: list<array<string, mixed>>, threads: list<array<string, mixed>>, needsYouCount: int, hasAnyThreads: bool}
     */
    public static function for(User $user, ThreadListFilter $filter, ?string $search): array
    {
        $list = ThreadList::for($user, $filter, $search);
        $taskItems = $filter === ThreadListFilter::Closed ? [] : NeedsYouTasks::for($user, $search);
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
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function typed(array $rows): array
    {
        return array_map(fn (array $row): array => ['type' => 'thread'] + $row, $rows);
    }
}
