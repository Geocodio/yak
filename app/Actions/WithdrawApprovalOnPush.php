<?php

namespace App\Actions;

use App\Channels\GitHub\AppService;
use App\Models\PrReview;
use App\Models\Repository;
use Throwable;

class WithdrawApprovalOnPush
{
    public function __construct(
        private readonly AppService $github,
        private readonly EnqueuePrReview $enqueuePrReview,
    ) {}

    /**
     * Withdraws Yak's standing approval of a pull request that received a new
     * commit and starts a full review of the new head.
     *
     * @param  array<string, mixed>  $pullRequest
     */
    public function handle(Repository $repository, array $pullRequest): bool
    {
        $approval = PrReview::query()
            ->where('pr_url', (string) $pullRequest['html_url'])
            ->whereNull('dismissed_at')
            ->latest('id')
            ->first();

        if ($approval === null || ($approval->risk_assessment['event'] ?? null) !== 'APPROVE') {
            return false;
        }

        try {
            $this->github->dismissPullRequestReviewOrFail(
                (int) config('yak.channels.github.installation_id'),
                $repository->github_full_name,
                (int) $pullRequest['number'],
                (int) $approval->github_review_id,
                'New commits since Yak approved. Reviewing again.',
            );

            $approval->update(['dismissed_at' => now()]);
        } catch (Throwable $exception) {
            report($exception);
        }

        $this->enqueuePrReview->dispatch($repository, $pullRequest, 'full', null);

        return true;
    }
}
