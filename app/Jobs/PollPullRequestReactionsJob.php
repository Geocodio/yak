<?php

namespace App\Jobs;

use App\Models\PrReviewComment;
use App\Models\PrReviewCommentReaction;
use App\Services\GitHubAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class PollPullRequestReactionsJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public function __construct()
    {
        $this->onQueue('yak-poll');
    }

    public function handle(GitHubAppService $github): void
    {
        $windowDays = (int) config('yak.pr_review.reaction_poll_window_days', 30);
        $installationId = (int) config('yak.channels.github.installation_id');
        $cutoff = now()->subDays($windowDays);

        $query = PrReviewComment::query()
            ->whereHas('review', function ($q) use ($cutoff): void {
                $q->where(function ($qq) use ($cutoff): void {
                    $qq->whereNull('pr_closed_at')
                        ->orWhere('pr_closed_at', '>=', $cutoff);
                });
            });

        $query->chunkById(100, function ($comments) use ($github, $installationId): void {
            foreach ($comments as $comment) {
                try {
                    $this->pollOne($github, $installationId, $comment);
                } catch (\Throwable $e) {
                    Log::warning('Reaction poll failed for comment', [
                        'comment_id' => $comment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        });
    }

    private function pollOne(GitHubAppService $github, int $installationId, PrReviewComment $comment): void
    {
        $review = $comment->review;
        if ($review === null) {
            return;
        }

        $reactions = $github->listCommentReactions($installationId, $review->repo, (int) $comment->github_comment_id);

        $seenIds = [];

        foreach ($reactions as $r) {
            $content = (string) ($r['content'] ?? '');
            if (! in_array($content, ['+1', '-1'], true)) {
                continue;
            }

            $seenIds[] = (int) $r['id'];

            PrReviewCommentReaction::updateOrCreate(
                ['github_reaction_id' => (int) $r['id']],
                [
                    'pr_review_comment_id' => $comment->id,
                    'github_user_login' => (string) ($r['user']['login'] ?? ''),
                    'github_user_id' => (int) ($r['user']['id'] ?? 0),
                    'content' => $content,
                    'reacted_at' => (string) ($r['created_at'] ?? now()),
                ],
            );
        }

        PrReviewCommentReaction::where('pr_review_comment_id', $comment->id)
            ->whereNotIn('github_reaction_id', $seenIds)
            ->delete();

        $up = PrReviewCommentReaction::where('pr_review_comment_id', $comment->id)->where('content', '+1')->count();
        $down = PrReviewCommentReaction::where('pr_review_comment_id', $comment->id)->where('content', '-1')->count();

        $comment->update([
            'thumbs_up' => $up,
            'thumbs_down' => $down,
            'last_polled_at' => now(),
        ]);
    }
}
