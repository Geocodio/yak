<?php

namespace App\Services;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Enums\TaskStatus;
use App\Jobs\ProcessCIResultJob;
use App\Models\Repository;
use App\Models\YakTask;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * How a task branch's failing checks relate to the same checks on the default
 * branch, and how a branch gets updated from the default branch.
 */
class DefaultBranchCi
{
    /** How long a task waits for the default branch before giving up. */
    public const int WAIT_LIMIT_HOURS = 24;

    public const string RESYNCED_LOG_MESSAGE = 'Branch updated from the default branch, waiting for CI';

    private const array PASSING_CONCLUSIONS = ['success', 'skipped', 'neutral'];

    public function __construct(private readonly GitHubAppService $gitHub) {}

    /**
     * The names of the checks failing and passing on a ref (a branch name or commit sha). Checks that
     * have not finished are in neither list. Throws when GitHub cannot be read.
     *
     * @return array{failing: list<string>, passing: list<string>}
     */
    public function checksOn(Repository $repository, string $ref): array
    {
        $installationId = (int) config('yak.channels.github.installation_id');
        $failing = [];
        $passing = [];

        foreach ($this->gitHub->getCheckRunSummaries($installationId, $repository->github_full_name, $ref) as $check) {
            if ($check['conclusion'] === null) {
                continue;
            }

            if (in_array($check['conclusion'], self::PASSING_CONCLUSIONS, true)) {
                $passing[] = $check['name'];
            } else {
                $failing[] = $check['name'];
            }
        }

        return ['failing' => array_values(array_unique($failing)), 'passing' => array_values(array_unique($passing))];
    }

    /**
     * The branch's failing checks next to the default branch's. Throws when GitHub cannot be read.
     *
     * @return array{branchFailing: list<string>, defaultFailing: list<string>, defaultHasChecks: bool}
     */
    public function compare(Repository $repository, string $branch): array
    {
        $onBranch = $this->checksOn($repository, $branch);
        $onDefault = $this->checksOn($repository, $repository->default_branch);

        return [
            'branchFailing' => $onBranch['failing'],
            'defaultFailing' => $onDefault['failing'],
            'defaultHasChecks' => $onDefault['failing'] !== [] || $onDefault['passing'] !== [],
        ];
    }

    /**
     * Whether every check that failed on the branch head also failed on the default branch head. False when
     * nothing failed on the branch or either side cannot be read.
     */
    public function failingChecksAlsoFailOnDefaultBranch(YakTask $task, Repository $repository): bool
    {
        if ((int) config('yak.channels.github.installation_id') === 0 || $task->branch_name === null) {
            return false;
        }

        try {
            $comparison = $this->compare($repository, $task->branch_name);

            return $comparison['branchFailing'] !== [] && array_diff($comparison['branchFailing'], $comparison['defaultFailing']) === [];
        } catch (Throwable $e) {
            Log::channel('yak')->warning('Could not compare failing checks with the default branch', ['task_id' => $task->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    /**
     * Whether the default branch head already passed every named check.
     *
     * @param  list<string>  $checks
     */
    public function defaultBranchPasses(Repository $repository, array $checks): bool
    {
        $onDefault = $this->checksOn($repository, $repository->default_branch);

        return $checks !== [] && array_diff($checks, $onDefault['passing']) === [];
    }

    /**
     * Whether the task's branch is behind the default branch. False when GitHub cannot say.
     */
    public function isBehindDefaultBranch(YakTask $task, Repository $repository): bool
    {
        try {
            return $task->branch_name !== null && $this->gitHub->commitsBehind(
                (int) config('yak.channels.github.installation_id'),
                $repository->github_full_name,
                $repository->default_branch,
                $task->branch_name,
            ) > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Records the default branch head the task syncs from, so one head is never synced twice.
     * Returns that head, or null when it was already synced or cannot be read.
     */
    public function claimDefaultBranchHead(YakTask $task, Repository $repository): ?string
    {
        try {
            $sha = $this->gitHub->getBranchHeadSha((int) config('yak.channels.github.installation_id'), $repository->github_full_name, $repository->default_branch);
        } catch (Throwable) {
            return null;
        }

        if ($sha === null) {
            return null;
        }

        // A conditional update, so one default branch head is claimed by one caller even when green jobs race.
        $claimed = YakTask::query()
            ->whereKey($task->id)
            ->where(fn ($query) => $query->whereNull('default_branch_synced_sha')->orWhere('default_branch_synced_sha', '!=', $sha))
            ->update(['default_branch_synced_sha' => $sha]);

        if ($claimed === 0) {
            return null;
        }

        $task->default_branch_synced_sha = $sha;
        $task->syncOriginalAttribute('default_branch_synced_sha');

        return $sha;
    }

    /**
     * Merges the default branch into the task's branch: through the pull request when one exists, directly otherwise.
     *
     * @return bool|null true when the branch was updated or already current, false on a merge conflict, null when GitHub failed
     */
    public function syncBranch(YakTask $task, Repository $repository): ?bool
    {
        $installationId = (int) config('yak.channels.github.installation_id');
        $prNumber = $task->pullRequestNumber();

        try {
            $response = $prNumber !== null
                ? $this->gitHub->updatePullRequestBranchResponse($installationId, $repository->github_full_name, $prNumber)
                : $this->gitHub->mergeBranchResponse($installationId, $repository->github_full_name, (string) $task->branch_name, $repository->default_branch);
        } catch (Throwable $e) {
            TaskLogger::warning($task, 'Could not update the branch from the default branch', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->successful()) {
            TaskLogger::info($task, 'Updated the branch from the default branch', ['branch' => $task->branch_name, 'default_branch' => $repository->default_branch]);

            return true;
        }

        return $this->isMergeConflict($response, $prNumber !== null) ? false : null;
    }

    /**
     * Back to waiting for CI on the updated branch. The log line restarts yak:timeout-ci's wait clock.
     */
    public function resumeAwaitingCi(YakTask $task): void
    {
        if ($task->status !== TaskStatus::AwaitingCi) {
            $task->update(['status' => TaskStatus::AwaitingCi]);
        }

        $task->update(['waiting_on_default_branch_at' => null, 'default_branch_wait_checks' => null]);

        TaskLogger::info($task, self::RESYNCED_LOG_MESSAGE);
    }

    /**
     * Updates every task parked on the repository whose checks now pass on the default branch. A task
     * whose update hits a merge conflict leaves the park and goes through the normal retry.
     */
    public function unparkReadyTasks(Repository $repository): void
    {
        $parked = YakTask::query()
            ->where('repo', $repository->slug)
            ->where('status', TaskStatus::AwaitingCi)
            ->whereNotNull('waiting_on_default_branch_at')
            ->get();

        foreach ($parked as $task) {
            try {
                if (! $this->defaultBranchPasses($repository, (array) $task->default_branch_wait_checks)) {
                    continue;
                }

                if ($this->claimDefaultBranchHead($task, $repository) === null) {
                    continue;
                }

                $result = $this->syncBranch($task, $repository);

                if ($result === true) {
                    $this->resumeAwaitingCi($task);
                } elseif ($result === false) {
                    $task->update(['waiting_on_default_branch_at' => null, 'default_branch_wait_checks' => null]);
                    TaskLogger::info($task, 'Default branch passes now, but updating the branch hit a merge conflict -- retrying');
                    ProcessCIResultJob::dispatch($task, passed: false, output: 'CI failed on the branch, and merging the default branch into it hit a merge conflict.');
                }
            } catch (Throwable $e) {
                Log::channel('yak')->warning('Could not unpark a task waiting on the default branch', ['task_id' => $task->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private function isMergeConflict(Response $response, bool $isPullRequest): bool
    {
        return $response->status() === ($isPullRequest ? 422 : 409);
    }
}
