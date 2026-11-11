<?php

namespace App\Services;

use App\Enums\DeploymentStatus;
use App\Enums\TaskMode;
use App\Models\BranchDeployment;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\YakTask;
use App\Support\BranchHostname;
use App\Support\ReleaseBranch;
use Illuminate\Support\Str;

class BranchDeploymentProvisioner
{
    public function provision(
        Repository $repository,
        string $branchName,
        ?int $pullRequestNumber = null,
        ?string $headRepository = null,
        ?string $headSha = null,
    ): BranchDeployment {
        $taskId = $this->isForkHead($repository, $headRepository)
            ? null
            : $this->threadTaskId($repository, $branchName, $pullRequestNumber, $headSha);

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
     * A head repository other than the base repository is a fork; its branch
     * name proves nothing about which thread produced it.
     */
    private function isForkHead(Repository $repository, ?string $headRepository): bool
    {
        return $headRepository !== null
            && $headRepository !== ''
            && strcasecmp($headRepository, $repository->github_full_name) !== 0;
    }

    /**
     * The latest non-review task of the one thread that owns this branch, so a
     * deployment inherits that thread's visibility. Candidates are scored by
     * how well they match the PR; inside the best tier a private thread wins,
     * then the newest thread. Null for branches no thread produced.
     */
    private function threadTaskId(Repository $repository, string $branchName, ?int $pullRequestNumber, ?string $headSha): ?int
    {
        $tasks = fn () => YakTask::withoutGlobalScopes()
            ->whereNotNull('thread_id')
            ->where('mode', '!=', TaskMode::Review);

        /** @var array<int, int> $tiers thread id => best tier */
        $tiers = [];
        $raise = function (int $threadId, int $tier) use (&$tiers): void {
            $tiers[$threadId] = max($tiers[$threadId] ?? 0, $tier);
        };

        foreach ($tasks()->where('repo', $repository->slug)->where('branch_name', $branchName)->get() as $task) {
            $matches = $pullRequestNumber !== null && $task->pullRequestNumber() === $pullRequestNumber;
            $raise((int) $task->thread_id, $matches ? 3 : 1);
        }

        $rows = ThreadRepository::query()
            ->where('branch', $branchName)
            ->whereIn('repo', array_filter([$repository->github_full_name, $repository->slug]))
            ->get();

        foreach ($rows as $row) {
            $matches = ($pullRequestNumber !== null && str_ends_with((string) $row->pull_request_url, '/pull/' . $pullRequestNumber))
                || ($headSha !== null && $headSha !== '' && $row->last_pushed_sha === $headSha);
            $raise((int) $row->thread_id, $matches ? 2 : 1);
        }

        if ($tiers === []) {
            return null;
        }

        $threadIds = array_keys($tiers, max($tiers), true);

        if (count($threadIds) > 1) {
            $threads = Thread::withoutVisibilityScope()->with('root')->whereIn('id', $threadIds)->get();
            $private = $threads->filter(fn (Thread $thread): bool => $thread->isPrivate());
            $threadIds = ($private->isNotEmpty() ? $private : $threads)->pluck('id')->all();
        }

        $threadId = (int) collect($threadIds)->max();

        $candidates = $tasks()->where('thread_id', $threadId)->latest('id')->get();
        $matching = $pullRequestNumber === null
            ? null
            : $candidates->first(fn (YakTask $task): bool => $task->pullRequestNumber() === $pullRequestNumber);
        $task = $matching ?? $candidates->first();

        return $task === null ? null : (int) $task->id;
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
