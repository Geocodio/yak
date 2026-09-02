<?php

namespace App\Http\Controllers\PrReviews;

use App\Actions\EnqueuePrReview;
use App\Channels\GitHub\AppService as GitHubAppService;
use App\Http\Controllers\Controller;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\Repository;
use App\Support\Markdown;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class PrReviewForPrController extends Controller
{
    public function show(string $repoSlug, int $prNumber): Response
    {
        return Inertia::render('PrReviews/ForPr', [
            'pr' => fn () => $this->prData($repoSlug, $prNumber),
            'reviews' => fn () => $this->reviewsData($repoSlug, $prNumber),
        ]);
    }

    public function rerun(string $repoSlug, int $prNumber, GitHubAppService $github): RedirectResponse
    {
        $installationId = (int) config('yak.channels.github.installation_id');
        $prPayload = $github->getPullRequest($installationId, $repoSlug, $prNumber);

        if (! isset($prPayload['head']['sha'])) {
            return redirect()->route('pr-reviews.for-pr', [$repoSlug, $prNumber])
                ->with('error', 'Failed to fetch PR from GitHub.');
        }

        $repository = Repository::where('slug', $repoSlug)->first();

        if ($repository === null) {
            return redirect()->route('pr-reviews.for-pr', [$repoSlug, $prNumber])
                ->with('error', 'Repository not found.');
        }

        $task = app(EnqueuePrReview::class)->dispatch($repository, $prPayload, 'full');

        if ($task === null) {
            return redirect()->route('pr-reviews.for-pr', [$repoSlug, $prNumber])
                ->with('error', 'A review is already queued for this PR.');
        }

        return redirect()->route('pr-reviews.for-pr', [$repoSlug, $prNumber])
            ->with('success', 'Re-running review for this PR.');
    }

    /**
     * @return array{repoSlug: string, number: int, title: string, url: string}
     */
    private function prData(string $repoSlug, int $prNumber): array
    {
        $latest = PrReview::query()
            ->where('repo', $repoSlug)
            ->where('pr_number', $prNumber)
            ->with('task')
            ->orderByDesc('submitted_at')
            ->first();

        $title = $this->titleFor($latest, $prNumber);

        return [
            'repoSlug' => $repoSlug,
            'number' => $prNumber,
            'title' => $title,
            'url' => "https://github.com/{$repoSlug}/pull/{$prNumber}",
        ];
    }

    private function titleFor(?PrReview $review, int $prNumber): string
    {
        $description = (string) ($review?->task->description ?? '');
        $prefix = "Review PR #{$prNumber}: ";

        if ($description !== '' && str_starts_with($description, $prefix)) {
            return substr($description, strlen($prefix));
        }

        return $description !== '' ? $description : "PR #{$prNumber}";
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function reviewsData(string $repoSlug, int $prNumber): array
    {
        $reviews = PrReview::query()
            ->where('repo', $repoSlug)
            ->where('pr_number', $prNumber)
            ->with('comments')
            ->orderByDesc('submitted_at')
            ->get();

        return $reviews->map(fn (PrReview $review): array => [
            'id' => $review->id,
            'scope' => $review->review_scope,
            'dismissed' => $review->dismissed_at !== null,
            'createdAgo' => $review->submitted_at?->diffForHumans(),
            'reviewer' => 'Yak',
            'commitSha' => $review->commit_sha_reviewed,
            'taskId' => $review->yak_task_id,
            'findings' => $this->findings($review),
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function findings(PrReview $review): array
    {
        /** @var Collection<int, PrReviewComment> $comments */
        $comments = $review->comments;

        return [
            'verdict' => (string) $review->verdict,
            'counts' => [
                'mustFix' => $comments->where('severity', 'must_fix')->count(),
                'shouldFix' => $comments->where('severity', 'should_fix')->count(),
                'consider' => $comments->where('severity', 'consider')->count(),
            ],
            'summaryHtml' => Markdown::toHtml($review->summary),
            'comments' => $comments->map(fn (PrReviewComment $comment): array => [
                'severity' => $comment->severity,
                'path' => $comment->file_path,
                'line' => $comment->line_number,
                'category' => $comment->category,
                'bodyHtml' => Markdown::toHtml($comment->body),
            ])->values()->all(),
        ];
    }
}
