<?php

namespace App\Jobs;

use App\Channels\GitHub\AppService;
use App\Models\ApprovalPreview;
use App\Models\PrReview;
use App\Services\ReviewApprovalPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Runs the approval policy dry run for one open pull request and stores the
 * outcome on its preview row. Posts nothing and writes no review.
 */
class CheckPullRequestApprovalJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 180;

    public int $tries = 1;

    public function __construct(public readonly int $approvalPreviewId)
    {
        $this->onQueue('default');
    }

    public function handle(AppService $github, ReviewApprovalPolicy $policy): void
    {
        $preview = ApprovalPreview::with('repository')->find($this->approvalPreviewId);
        if ($preview === null || $preview->status !== 'pending' || $preview->repository === null) {
            return;
        }

        $repository = $preview->repository;

        try {
            $installationId = (int) config('yak.channels.github.installation_id');
            $pullRequest = $github->getPullRequestOrFail($installationId, $repository->github_full_name, $preview->pr_number);
            if ($pullRequest === null) {
                throw new \RuntimeException('Could not load the pull request from GitHub.');
            }
            $headSha = (string) ($pullRequest['head']['sha'] ?? '');
            $files = $github->listPullRequestFilesOrFail($installationId, $repository->github_full_name, $preview->pr_number);

            $review = PrReview::where('repo', $repository->slug)
                ->where('pr_number', $preview->pr_number)
                ->where('commit_sha_reviewed', $headSha)
                ->latest('id')
                ->first();
            /** @var array{touched_concerns: list<array{name: string, evidence: string}>, change_kind: ?string, is_uncertain: bool, failure: ?string}|null $storedClassification */
            $storedClassification = $review?->risk_assessment['classification'] ?? null;
            $reuse = $review !== null && is_array($storedClassification)
                && ($review->risk_assessment['concerns_version'] ?? null) === $repository->settings()->concernsVersion();

            $result = $policy->preview($repository, $pullRequest, $files, $review, $reuse ? $storedClassification : null);
            $preview->update(['head_sha' => $headSha, 'status' => 'done', 'result' => $result, 'error' => null]);
        } catch (\Throwable $exception) {
            report($exception);
            $preview->update(['status' => 'failed', 'result' => null, 'error' => mb_substr($exception->getMessage(), 0, 500)]);
        }
    }
}
