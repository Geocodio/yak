<?php

namespace App\Services\Stacks;

use App\Channels\GitHub\AppService;
use App\Models\Repository;
use App\Models\Thread;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Deletes the remote branches a stack's implement and mockup children pushed,
 * once their root is closed.
 */
class StackBranchCleanup
{
    public function __construct(private readonly AppService $github) {}

    public function deleteChildBranches(Thread $root): int
    {
        $branches = Thread::query()->withoutGlobalScopes()
            ->where('root_thread_id', $root->id)
            ->where('id', '!=', $root->id)
            ->where('repo', $root->repo)
            ->where('branch', 'like', 'yak/%')
            ->pluck('branch')
            ->unique();

        $repository = Repository::githubNameFor($root->repo);
        $installationId = (int) config('yak.channels.github.installation_id');
        $deleted = 0;

        foreach ($branches as $branch) {
            try {
                $deleted += $this->github->deleteBranch($installationId, $repository, (string) $branch) ? 1 : 0;
            } catch (Throwable $exception) {
                Log::channel('yak')->warning('Deleting a sub-thread branch failed', ['thread' => $root->id, 'branch' => $branch, 'error' => $exception->getMessage()]);
            }
        }

        return $deleted;
    }
}
