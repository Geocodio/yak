<?php

namespace App\Services\CiFailure;

use App\Ai\Agents\SameCauseCheck;
use App\Channels\GitHub\AppService;
use App\DataTransferObjects\CiFailureQualification;
use App\Models\CiDefaultBranchGreen;
use App\Models\CiJobFailure;
use App\Models\Repository;
use Illuminate\Support\Collection;

/**
 * Decides whether a failed CI job has enough cross-branch evidence to be
 * treated as a cause that no single branch owns.
 */
class CiFailureQualifier
{
    public function __construct(
        private readonly AppService $appService,
        private readonly SameCauseCheck $sameCauseCheck,
    ) {}

    public function qualify(Repository $repository, CiJobFailure $failure): CiFailureQualification
    {
        if ($this->isStaleAfterGreen($failure)) {
            return new CiFailureQualification(false, 'stale_after_green', collect());
        }

        $candidates = $this->candidates($failure);

        if ($failure->branch === $repository->default_branch) {
            if ($failure->isTestJob() && $candidates->isEmpty()) {
                return new CiFailureQualification(false, 'deferred_to_flaky_scan', collect([$failure]));
            }

            return new CiFailureQualification(true, 'default_branch', collect([$failure])->merge($candidates)->values());
        }

        if ($candidates->isEmpty()) {
            return new CiFailureQualification(false, 'single_branch', collect([$failure]));
        }

        $candidates = $this->withSharedParent($repository, $failure, $candidates);

        if ($candidates->isEmpty()) {
            return new CiFailureQualification(false, 'shared_parent', collect([$failure]));
        }

        if (! $failure->isTestJob() && ! $this->sharesCause($repository, $failure, $candidates->sortByDesc('failed_at')->first())) {
            return new CiFailureQualification(false, 'different_causes', collect([$failure]));
        }

        return new CiFailureQualification(true, 'cross_branch', collect([$failure])->merge($candidates)->values());
    }

    private function isStaleAfterGreen(CiJobFailure $failure): bool
    {
        $green = CiDefaultBranchGreen::latestFor($failure->repo, $failure->workflow_name, $failure->job_name);

        return $green !== null && $failure->failed_at->lt($green);
    }

    /**
     * @return Collection<int, CiJobFailure>
     */
    private function candidates(CiJobFailure $failure): Collection
    {
        $windowStart = now()->subHours((int) config('yak.ci_failure.correlation_window_hours'));

        return CiJobFailure::query()
            ->unresolved()
            ->forJob($failure->repo, $failure->workflow_path)
            ->where('branch', '!=', $failure->branch)
            ->where('head_sha', '!=', $failure->head_sha)
            ->where('failed_at', '>=', $windowStart)
            ->get()
            ->reject(fn (CiJobFailure $candidate): bool => $this->isStaleAfterGreen($candidate))
            ->filter(fn (CiJobFailure $candidate): bool => $this->isSameFailure($failure, $candidate))
            ->values();
    }

    private function isSameFailure(CiJobFailure $failure, CiJobFailure $candidate): bool
    {
        if (! $failure->isTestJob()) {
            return $candidate->job_name === $failure->job_name
                && $candidate->failed_step === $failure->failed_step;
        }

        if (! $candidate->isTestJob()) {
            return false;
        }

        if (empty($failure->test_names) || empty($candidate->test_names)) {
            return $candidate->job_name === $failure->job_name;
        }

        return array_intersect($failure->test_names, $candidate->test_names) !== [];
    }

    /**
     * Keeps candidates whose merge base with the failure is on the default
     * branch, so the failure is not just a shared feature-branch parent.
     *
     * @param  Collection<int, CiJobFailure>  $candidates
     * @return Collection<int, CiJobFailure>
     */
    private function withSharedParent(Repository $repository, CiJobFailure $failure, Collection $candidates): Collection
    {
        $installationId = (int) config('yak.channels.github.installation_id');
        $defaultHeadSha = $this->appService->getBranchHeadSha(
            $installationId,
            $repository->github_full_name,
            $repository->default_branch,
        );

        if ($defaultHeadSha === null) {
            return $candidates;
        }

        return $candidates->filter(function (CiJobFailure $candidate) use ($repository, $failure, $installationId, $defaultHeadSha): bool {
            $comparison = $this->appService->compareCommits(
                $installationId,
                $repository->github_full_name,
                $candidate->head_sha,
                $failure->head_sha,
            );

            return $this->appService->isAncestor(
                $installationId,
                $repository->github_full_name,
                $comparison['merge_base_sha'],
                $defaultHeadSha,
            );
        })->values();
    }

    private function sharesCause(Repository $repository, CiJobFailure $failure, CiJobFailure $candidate): bool
    {
        $installationId = (int) config('yak.channels.github.installation_id');
        $lines = (int) config('yak.ci_failure.log_excerpt_lines');

        $first = $this->appService->getJobLogExcerpt($installationId, $repository->github_full_name, $failure->job_id, $lines);
        $second = $this->appService->getJobLogExcerpt($installationId, $repository->github_full_name, $candidate->job_id, $lines);

        if ($first === null || $second === null) {
            return true;
        }

        return $this->sameCauseCheck->shareCause($first, $second);
    }
}
