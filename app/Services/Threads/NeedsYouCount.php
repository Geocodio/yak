<?php

namespace App\Services\Threads;

use App\Enums\ThreadListFilter;
use App\Models\User;

/**
 * The one "needs you" number: threads that need the person plus tasks outside
 * an open thread that do. It never depends on a list's filter or search.
 */
class NeedsYouCount
{
    public static function for(User $user, ?int $mineThreadCount = null): int
    {
        $threads = $mineThreadCount ?? ThreadList::for($user, ThreadListFilter::Mine)['needsYouCount'];

        return $threads + NeedsYouTasks::count($user);
    }
}
