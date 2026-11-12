<?php

namespace App\Services\Threads\Repos;

use App\Enums\RepositoryRole;
use App\Models\Thread;
use App\Models\ThreadRepository;

/**
 * A Changes extra works on the thread branch, the same name the primary uses.
 * A row's own branch always wins over the thread branch.
 */
class ThreadRepositoryBranches
{
    public function assign(Thread $thread): void
    {
        if (blank($thread->branch)) {
            return;
        }

        $thread->repositories()
            ->where('role', RepositoryRole::Changes)
            ->whereNull('branch')
            ->update(['branch' => $thread->branch]);
    }

    public function branchFor(Thread $thread, ThreadRepository $row): ?string
    {
        return $row->branch ?? (filled($thread->branch) ? $thread->branch : null);
    }
}
