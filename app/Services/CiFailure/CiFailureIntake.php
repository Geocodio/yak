<?php

namespace App\Services\CiFailure;

use App\Models\CiDefaultBranchGreen;
use App\Models\CiJobFailure;
use App\Models\Repository;
use Illuminate\Http\Request;

/**
 * Synchronous filtering for workflow_job webhooks, plus the bookkeeping that
 * closes out recorded failures when a job goes green or its branch goes away.
 */
class CiFailureIntake
{
    /**
     * The repository when the event is a completed failure, timeout or
     * success on a branch and job that count, otherwise null.
     */
    public function accepts(Request $request): ?Repository
    {
        if ($request->input('action') !== 'completed') {
            return null;
        }

        if (! in_array($request->input('workflow_job.conclusion'), ['failure', 'timed_out', 'success'], true)) {
            return null;
        }

        $login = (string) $request->input('sender.login', '');

        if ($request->input('sender.type') === 'Bot' || str_ends_with($login, '[bot]')) {
            return null;
        }

        $branch = $request->input('workflow_job.head_branch');

        if (! is_string($branch) || $branch === '') {
            return null;
        }

        foreach ((array) config('yak.ci_failure.ignored_branch_prefixes') as $prefix) {
            if (str_starts_with($branch, (string) $prefix)) {
                return null;
            }
        }

        $jobName = (string) $request->input('workflow_job.name', '');

        foreach ((array) config('yak.ci_failure.job_exclude_patterns') as $pattern) {
            if (stripos($jobName, (string) $pattern) !== false) {
                return null;
            }
        }

        $repository = Repository::resolveFromGitHub(
            $request->input('repository.id') !== null ? (int) $request->input('repository.id') : null,
            (string) $request->input('repository.full_name'),
        );

        if ($repository === null || ! $repository->is_active || $repository->settings()->ciSystem() !== 'github_actions') {
            return null;
        }

        return $repository;
    }

    public function recordGreen(Repository $repository, Request $request): void
    {
        $repo = $repository->slug;
        $branch = (string) $request->input('workflow_job.head_branch');
        $workflowName = (string) $request->input('workflow_job.workflow_name');
        $jobName = (string) $request->input('workflow_job.name');

        CiJobFailure::query()
            ->unresolved()
            ->where('repo', $repo)
            ->where('workflow_name', $workflowName)
            ->where('job_name', $jobName)
            ->where('branch', $branch)
            ->update(['resolved_at' => now(), 'resolution' => 'green']);

        if ($branch === $repository->default_branch) {
            CiDefaultBranchGreen::updateOrCreate(
                ['repo' => $repo, 'workflow_name' => $workflowName, 'job_name' => $jobName],
                ['completed_at' => $request->input('workflow_job.completed_at') ?? now()],
            );
        }
    }

    public function resolveBranch(string $repo, string $branch, string $resolution): void
    {
        CiJobFailure::query()
            ->unresolved()
            ->where('repo', $repo)
            ->where('branch', $branch)
            ->update(['resolved_at' => now(), 'resolution' => $resolution]);
    }
}
