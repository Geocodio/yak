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
     * Workflow run events whose runs execute code the repository itself
     * controls. Runs triggered by issues, comments or pull_request_target
     * execute on the default branch for an outsider's action.
     */
    public const array TRUSTED_EVENTS = ['push', 'pull_request', 'schedule', 'merge_group', 'workflow_dispatch'];

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

    /**
     * Whether a passing job could change anything: it is on the default
     * branch, or it matches an unresolved failure.
     */
    public function greenMatters(Repository $repository, Request $request): bool
    {
        $branch = (string) $request->input('workflow_job.head_branch');

        return $branch === $repository->default_branch
            || CiJobFailure::query()
                ->unresolved()
                ->where('repo', $repository->slug)
                ->where('workflow_name', (string) $request->input('workflow_job.workflow_name'))
                ->where('job_name', (string) $request->input('workflow_job.name'))
                ->where('branch', $branch)
                ->exists();
    }

    /**
     * Closes failures of a pull request's branch, only when the pull request
     * comes from the repository itself and not from a fork or its default branch.
     */
    public function resolveClosedPullRequest(Request $request): void
    {
        $repository = Repository::resolveFromGitHub(
            $request->input('repository.id') !== null ? (int) $request->input('repository.id') : null,
            (string) $request->input('repository.full_name'),
        );
        $branch = (string) $request->input('pull_request.head.ref');

        if ($repository === null
            || ! $repository->is_active
            || $branch === ''
            || $branch === $repository->default_branch
            || $request->input('pull_request.head.repo.full_name') !== $request->input('repository.full_name')) {
            return;
        }

        $this->resolveBranch($repository->slug, $branch, 'pr_closed');
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
