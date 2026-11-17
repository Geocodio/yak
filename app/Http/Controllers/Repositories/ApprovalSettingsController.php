<?php

namespace App\Http\Controllers\Repositories;

use App\Actions\EnqueuePrReview;
use App\Channels\GitHub\AppService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Repositories\UpdateApprovalSettingsRequest;
use App\Http\Resources\ApprovalSettingsPageData;
use App\Http\Resources\RepositorySettingsData;
use App\Jobs\CheckOpenPullRequestApprovalsJob;
use App\Models\Repository;
use App\Services\RepositoryConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalSettingsController extends Controller
{
    public function show(Repository $repository): Response
    {
        $snapshot = app(RepositoryConfig::class)->snapshot($repository);

        return Inertia::render('Repositories/Approvals', [
            'header' => RepositorySettingsData::header($repository),
            ...RepositorySettingsData::yakConfigProp($repository),
            'repository' => ApprovalSettingsPageData::repository($repository),
            'approval' => ApprovalSettingsPageData::approval($repository),
            'concerns' => ApprovalSettingsPageData::concerns($repository, $snapshot),
            'shadow' => ApprovalSettingsPageData::shadow($repository),
            'openPullRequests' => fn (): array => ApprovalSettingsPageData::openPullRequests($repository),
            'repositoryHolds' => fn (): array => ApprovalSettingsPageData::repositoryHolds($repository),
            'github' => Inertia::defer(fn (): array => ApprovalSettingsPageData::github($repository), 'approvals-github'),
        ]);
    }

    public function update(UpdateApprovalSettingsRequest $request, Repository $repository): RedirectResponse
    {
        $level = $request->integer('level');
        $mode = $level === 1 ? 'shadow' : $request->string('mode')->toString();

        if ($mode === 'enforce' && $repository->approvalMode() !== 'enforce') {
            $this->ensureRequiredChecks($repository);
        }

        $repository->setApproval($level, $mode, $request->user());

        $label = match (true) {
            $level === 1 => 'review only',
            $mode === 'enforce' => 'armed',
            default => 'shadow',
        };

        return back()->with('success', "DEFCON {$level} " . ApprovalSettingsPageData::CODENAMES[$level] . ", {$label} is live.");
    }

    public function check(Repository $repository): RedirectResponse
    {
        CheckOpenPullRequestApprovalsJob::dispatch($repository->id);

        return back()->with('success', 'Checking open pull requests.');
    }

    public function review(Repository $repository, int $number, EnqueuePrReview $enqueuePrReview): RedirectResponse
    {
        try {
            $pullRequest = app(AppService::class)->getPullRequestOrFail((int) config('yak.channels.github.installation_id'), $repository->github_full_name, $number);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->with('error', 'Could not load the pull request from GitHub. Try again.');
        }

        if ($pullRequest === null) {
            return back()->with('error', 'Could not load the pull request from GitHub. Try again.');
        }

        $isReviewable = ($pullRequest['state'] ?? '') === 'open' && ! ($pullRequest['draft'] ?? true)
            && strcasecmp((string) ($pullRequest['user']['login'] ?? ''), app(AppService::class)->appBotLogin()) !== 0;
        if (! $isReviewable) {
            return back()->with('error', "Pull request #{$number} is not open for review.");
        }

        $enqueuePrReview($repository, $pullRequest, 'full');

        return back()->with('success', "Review of pull request #{$number} requested.");
    }

    private function ensureRequiredChecks(Repository $repository): void
    {
        try {
            $github = app(AppService::class);
            $installationId = (int) config('yak.channels.github.installation_id');
            $hasChecks = $github->hasRequiredStatusChecks($installationId, $repository->github_full_name, $repository->default_branch);
            $dismissesStale = $github->dismissesStaleReviews($installationId, $repository->github_full_name, $repository->default_branch);
        } catch (\Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['mode' => 'Could not check branch protection on GitHub. Try again.']);
        }

        if (! $hasChecks) {
            throw ValidationException::withMessages(['mode' => "Arming is locked: {$repository->default_branch} has no required status checks."]);
        }

        if (! $dismissesStale) {
            throw ValidationException::withMessages(['mode' => "Arming is locked: {$repository->default_branch} must dismiss stale approvals when new commits are pushed."]);
        }
    }
}
