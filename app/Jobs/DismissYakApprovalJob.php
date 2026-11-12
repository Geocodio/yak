<?php

namespace App\Jobs;

use App\Channels\GitHub\AppService;
use App\Models\PrReview;
use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Retries a failed dismissal of one of Yak's approvals until GitHub confirms it.
 */
class DismissYakApprovalJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public readonly int $prReviewId)
    {
        $this->onQueue('default');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(AppService $github): void
    {
        $review = PrReview::find($this->prReviewId);

        if ($review === null || $review->dismissed_at !== null || $review->github_review_id === null) {
            return;
        }

        $repository = Repository::where('slug', $review->repo)->first();

        if ($repository === null) {
            return;
        }

        try {
            $github->dismissPullRequestReviewOrFail(
                (int) config('yak.channels.github.installation_id'),
                $repository->github_full_name,
                (int) $review->pr_number,
                (int) $review->github_review_id,
                'New commits since Yak approved. Reviewing again.',
            );
        } catch (RequestException $exception) {
            // A 422 means GitHub will not dismiss it: already dismissed or not dismissable.
            if ($exception->response->status() !== 422) {
                throw $exception;
            }
        }

        $review->update(['dismissed_at' => now()]);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Could not dismiss a stale Yak approval after all attempts', [
            'pr_review_id' => $this->prReviewId,
            'error' => $exception->getMessage(),
        ]);
        report($exception);
    }
}
