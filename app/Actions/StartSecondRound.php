<?php

namespace App\Actions;

use App\Models\PrReview;
use App\Models\Repository;

class StartSecondRound
{
    public function __construct(private readonly EnqueuePrReview $enqueuePrReview) {}

    /**
     * Starts a full review when the developer signals that fixes are ready:
     * the repository is at approval level 2 or higher, Yak's latest standing
     * review had findings, and the current head has not been reviewed yet.
     * The trigger is one of threads_resolved, comment or quiet_period.
     *
     * @param  array<string, mixed>  $pullRequest
     */
    public function handle(Repository $repository, array $pullRequest, string $trigger): bool
    {
        if ($repository->approvalLevel() < 2
            || ($pullRequest['state'] ?? 'open') !== 'open'
            || $this->enqueuePrReview->skipReason($repository, $pullRequest) !== null) {
            return false;
        }

        $prUrl = (string) $pullRequest['html_url'];

        $latest = PrReview::query()
            ->where('pr_url', $prUrl)
            ->whereNull('dismissed_at')
            ->latest('id')
            ->first();

        if ($latest === null || ! $this->hadFindings($latest)) {
            return false;
        }

        $alreadyReviewed = PrReview::query()
            ->where('pr_url', $prUrl)
            ->where('commit_sha_reviewed', (string) $pullRequest['head']['sha'])
            ->exists();

        if ($alreadyReviewed) {
            return false;
        }

        return $this->enqueuePrReview->dispatch($repository, $pullRequest, 'full', null) !== null;
    }

    /**
     * The review records how many findings it parsed, so findings that
     * landed outside the diff and have no comment row still count.
     */
    private function hadFindings(PrReview $review): bool
    {
        return (int) ($review->risk_assessment['findings_count'] ?? 0) > 0
            || $review->comments()->exists();
    }
}
