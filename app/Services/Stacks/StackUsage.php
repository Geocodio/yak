<?php

namespace App\Services\Stacks;

use App\Models\TaskRun;
use App\Models\Thread;
use Carbon\CarbonImmutable;

/**
 * What a whole stack has used so far. Cost is the API-equivalent estimate;
 * subscription accounts are not billed per token.
 */
class StackUsage
{
    /**
     * @return array{turnCount: int, costUsd: float, activeHours: float, childCount: int}
     */
    public function for(Thread $root): array
    {
        $threadIds = Thread::query()->withoutGlobalScopes()->where('root_thread_id', $root->id)->pluck('id')->push($root->id);
        $runs = TaskRun::query()->whereIn('thread_id', $threadIds);

        $firstStart = (clone $runs)->min('started_at');
        $hasOpenRun = (clone $runs)->whereNull('finished_at')->exists();
        $lastActivity = $hasOpenRun ? null : (clone $runs)->max('finished_at');
        $end = $lastActivity === null ? CarbonImmutable::now() : CarbonImmutable::parse($lastActivity);
        $activeHours = $firstStart === null ? 0.0 : max(0.0, CarbonImmutable::parse($firstStart)->diffInSeconds($end, true) / 3600);

        return [
            'turnCount' => (clone $runs)->count(),
            'costUsd' => round((float) (clone $runs)->sum('recorded_cost_usd'), 4),
            'activeHours' => round($activeHours, 2),
            'childCount' => Thread::query()->withoutGlobalScopes()->where('root_thread_id', $root->id)->whereNotNull('parent_thread_id')->count(),
        ];
    }
}
