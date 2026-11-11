<?php

namespace App\Services\Stacks;

use App\Enums\ThreadKind;
use App\Enums\ThreadStatus;
use App\Models\Thread;
use App\Services\Threads\ThreadCloser;

/**
 * Closes every open child of a stack root. Research children go first, so
 * their worktrees and placeholders are gone before any container teardown.
 */
class StackThreadCloser
{
    public function closeChildren(Thread $root): int
    {
        $closer = app(ThreadCloser::class);
        $closed = 0;

        $children = Thread::query()->withoutGlobalScopes()
            ->where('parent_thread_id', $root->id)
            ->where('status', ThreadStatus::Open)
            ->get()
            ->sortBy(fn (Thread $child): string => ($child->kind === ThreadKind::Research ? '0' : '1') . str_pad((string) $child->id, 12, '0', STR_PAD_LEFT));

        foreach ($children as $child) {
            $closer->close($child, 'parent_closed');
            $closed++;
        }

        return $closed;
    }
}
