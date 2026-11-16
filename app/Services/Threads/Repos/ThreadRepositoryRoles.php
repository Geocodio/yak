<?php

namespace App\Services\Threads\Repos;

use App\Enums\RepositoryRole;
use App\Events\ThreadRepositoryChanged;
use App\Exceptions\RepositoryRoleBlocked;
use App\Models\ThreadDiffRepository;
use App\Models\ThreadRepository;

/**
 * Switches an extra repository between "changes" (gets a pull request) and
 * "context" (read only, discarded with the sandbox).
 */
class ThreadRepositoryRoles
{
    public function promoteToChanges(ThreadRepository $repository): ThreadRepository
    {
        if ($repository->role !== RepositoryRole::Context) {
            throw new RepositoryRoleBlocked($repository->isPrimary() ? 'The primary repository always gets changes.' : 'This repo already gets changes.');
        }

        $thread = $repository->thread()->firstOrFail();
        $repository->update(['role' => RepositoryRole::Changes, 'branch' => $thread->branch]);

        $this->syncStoredDiff($repository);
        ThreadRepositoryChanged::dispatch($thread, $repository);

        return $repository;
    }

    public function makeContextOnly(ThreadRepository $repository): ThreadRepository
    {
        if ($repository->role !== RepositoryRole::Changes) {
            throw new RepositoryRoleBlocked($repository->isPrimary() ? 'The primary repository always gets changes.' : 'This repo is already context only.');
        }

        if ($repository->hasOpenPullRequest()) {
            throw new RepositoryRoleBlocked('Close the pull request first, then make this repo context only.');
        }

        $repository->update(['role' => RepositoryRole::Context, 'branch' => null, 'merge_position' => null]);

        $this->syncStoredDiff($repository);
        ThreadRepositoryChanged::dispatch($repository->thread()->firstOrFail(), $repository);

        return $repository;
    }

    /** Keeps the stored diff's throwaway flag in step until the next capture. */
    private function syncStoredDiff(ThreadRepository $repository): void
    {
        ThreadDiffRepository::query()
            ->where('repo', $repository->repo)
            ->whereHas('diff', fn ($query) => $query->where('thread_id', $repository->thread_id))
            ->update(['role' => $repository->role->value, 'is_throwaway' => ! $repository->getsPullRequest()]);
    }
}
