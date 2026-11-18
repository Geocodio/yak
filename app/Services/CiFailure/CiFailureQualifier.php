<?php

namespace App\Services\CiFailure;

use App\Ai\Agents\SameCauseCheck;
use App\Channels\GitHub\AppService;
use App\DataTransferObjects\CiFailureQualification;
use App\Models\CiDefaultBranchGreen;
use App\Models\CiJobFailure;
use App\Models\Repository;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Decides whether a failed CI job has enough cross-branch evidence to be
 * treated as a cause that no single branch owns.
 */
class CiFailureQualifier
{
    public const MAXIMUM_CANDIDATES = 10;

    /** @var array<string, CarbonImmutable|null> */
    private array $latestGreens = [];

    public function __construct(
        private readonly AppService $appService,
        private readonly SameCauseCheck $sameCauseCheck,
    ) {}

    public function qualify(Repository $repository, CiJobFailure $failure): CiFailureQualification
    {
        $this->latestGreens = [];

        if ($this->isStaleAfterGreen($failure)) {
            return new CiFailureQualification(false, 'stale_after_green', collect([$failure]));
        }

        $candidates = $this->withDefaultBranchTargets($repository, $this->candidates($failure));

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

        if (! $failure->isTestJob() && ! $this->sharesCause($repository, $failure, $candidates->sortByDesc('failed_at')->firstOrFail())) {
            return new CiFailureQualification(false, 'different_causes', collect([$failure]));
        }

        return new CiFailureQualification(true, 'cross_branch', collect([$failure])->merge($candidates)->values());
    }

    private function isStaleAfterGreen(CiJobFailure $failure): bool
    {
        $key = $failure->repo . '|' . $failure->workflow_name . '|' . $failure->job_name;

        if (! array_key_exists($key, $this->latestGreens)) {
            $this->latestGreens[$key] = CiDefaultBranchGreen::latestFor($failure->repo, $failure->workflow_name, $failure->job_name);
        }

        $green = $this->latestGreens[$key];

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
            ->sortByDesc('failed_at')
            ->take(self::MAXIMUM_CANDIDATES)
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
     * Drops candidates whose open pull request targets a branch other than
     * the default branch: a stacked pull request fails for its parent's
     * reasons. A branch with no open pull request, or a lookup that fails,
     * stays a candidate.
     *
     * @param  Collection<int, CiJobFailure>  $candidates
     * @return Collection<int, CiJobFailure>
     */
    private function withDefaultBranchTargets(Repository $repository, Collection $candidates): Collection
    {
        $installationId = (int) config('yak.channels.github.installation_id');

        return $candidates->filter(function (CiJobFailure $candidate) use ($repository, $installationId): bool {
            try {
                $pullRequest = $this->appService->findOpenPullRequestForBranch($installationId, $repository->github_full_name, $candidate->branch);
            } catch (Throwable $exception) {
                report($exception);

                return true;
            }

            $base = data_get($pullRequest, 'base.ref');

            return $base === null || $base === $repository->default_branch;
        })->values();
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
            $mergeBase = $this->mergeBase($installationId, $repository, $candidate->head_sha, $failure->head_sha);

            return $mergeBase !== '' && $this->appService->isAncestor(
                $installationId,
                $repository->github_full_name,
                $mergeBase,
                $defaultHeadSha,
            );
        })->values();
    }

    /**
     * The merge base of two commits, or an empty string when GitHub does not
     * know one of them (a force-pushed away sha, for example). A commit pair's
     * merge base never changes, so the answer is cached for a day.
     */
    private function mergeBase(int $installationId, Repository $repository, string $baseSha, string $headSha): string
    {
        return Cache::remember(
            "ci-failure-merge-base:{$repository->github_full_name}:{$baseSha}:{$headSha}",
            now()->addDay(),
            function () use ($installationId, $repository, $baseSha, $headSha): string {
                try {
                    return $this->appService->compareCommits($installationId, $repository->github_full_name, $baseSha, $headSha)['merge_base_sha'];
                } catch (RequestException $exception) {
                    if ($exception->response->status() === 404) {
                        return '';
                    }

                    throw $exception;
                }
            },
        );
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
