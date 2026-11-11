<?php

namespace App\Services;

use App\Enums\DeploymentStatus;
use App\Enums\TaskMode;
use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Models\ThreadRepository;
use App\Models\YakTask;
use App\Support\BranchHostname;
use App\Support\ReleaseBranch;
use Illuminate\Support\Str;

class BranchDeploymentProvisioner
{
    public function provision(Repository $repository, string $branchName): BranchDeployment
    {
        $taskId = $this->threadTaskId($repository, $branchName);

        $deployment = BranchDeployment::withoutGlobalScopes()->firstOrCreate(
            [
                'repository_id' => $repository->id,
                'branch_name' => $branchName,
            ],
            [
                'hostname' => $this->uniqueHostname($repository, $branchName),
                'container_name' => 'deploy-' . Str::lower((string) Str::ulid()),
                'template_version' => max(1, $repository->current_template_version ?? 0),
                'status' => DeploymentStatus::Pending,
                'long_lived' => ReleaseBranch::matches($branchName),
                'yak_task_id' => $taskId,
            ],
        );

        if ($deployment->yak_task_id === null && $taskId !== null) {
            $deployment->update(['yak_task_id' => $taskId]);
        }

        return $deployment;
    }

    /**
     * The latest non-review task of the thread that owns this branch, so a
     * deployment inherits the thread's visibility. Null for branches no
     * thread produced.
     */
    private function threadTaskId(Repository $repository, string $branchName): ?int
    {
        $threadTasks = fn () => YakTask::withoutGlobalScopes()
            ->whereNotNull('thread_id')
            ->where('mode', '!=', TaskMode::Review)
            ->latest('id');

        $direct = $threadTasks()
            ->where('repo', $repository->slug)
            ->where('branch_name', $branchName)
            ->value('id');

        if ($direct !== null) {
            return (int) $direct;
        }

        $threadId = ThreadRepository::query()
            ->where('branch', $branchName)
            ->whereIn('repo', array_filter([$repository->github_full_name, $repository->slug]))
            ->value('thread_id');

        if ($threadId === null) {
            return null;
        }

        $fallback = $threadTasks()->where('thread_id', $threadId)->value('id');

        return $fallback === null ? null : (int) $fallback;
    }

    private function uniqueHostname(Repository $repository, string $branchName): string
    {
        $suffix = (string) config('yak.deployments.hostname_suffix');
        $natural = BranchHostname::build($repository->slug, $branchName, $suffix);

        $exists = BranchDeployment::where('hostname', $natural)->exists();

        return $exists
            ? BranchHostname::withCollisionSuffix($repository->slug, $branchName, $suffix)
            : $natural;
    }
}
