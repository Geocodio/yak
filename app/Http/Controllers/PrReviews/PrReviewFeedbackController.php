<?php

namespace App\Http\Controllers\PrReviews;

use App\Http\Controllers\Controller;
use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\PrReviewCommentReaction;
use App\Support\Markdown;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class PrReviewFeedbackController extends Controller
{
    /** @var list<string> */
    private const array SORTABLE_COLUMNS = ['submitted_at', 'severity', 'category', 'file_path', 'thumbs_up', 'thumbs_down'];

    public function __invoke(Request $request): Response
    {
        $repo = $request->string('repo')->toString();
        $severity = $request->string('severity')->toString();
        $category = $request->string('category')->toString();
        $scope = $request->string('scope')->toString();
        $reviewer = $request->string('reviewer')->toString();
        $reactions = $request->boolean('reactions');
        $sort = $request->string('sort', 'submitted_at')->toString();
        $dir = $request->string('dir', 'desc')->toString() === 'asc' ? 'asc' : 'desc';
        $tab = $request->string('tab', 'all')->toString();

        $sortColumn = in_array($sort, self::SORTABLE_COLUMNS, true) ? $sort : 'submitted_at';

        return Inertia::render('PrReviews/Index', [
            'comments' => fn () => $this->paginatedComments($repo, $severity, $category, $scope, $reviewer, $reactions, $sortColumn, $dir),
            'stats' => fn () => $this->stats(),
            'reviewerStats' => fn () => $this->reviewerStats(),
            'filters' => [
                'repo' => $repo,
                'severity' => $severity,
                'category' => $category,
                'scope' => $scope,
                'reviewer' => $reviewer,
                'reactions' => $reactions,
                'sort' => $sortColumn,
                'dir' => $dir,
                'tab' => $tab,
                'options' => fn () => [
                    'repos' => $this->distinctRepos(),
                    'categories' => $this->distinctCategories(),
                    'reviewers' => $this->distinctReviewers(),
                ],
            ],
        ]);
    }

    /**
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function paginatedComments(
        string $repo,
        string $severity,
        string $category,
        string $scope,
        string $reviewer,
        bool $reactions,
        string $sort,
        string $dir,
    ): LengthAwarePaginator {
        $query = PrReviewComment::query()
            ->with(['review', 'reactions'])
            ->when($severity !== '', fn (Builder $q) => $q->where('severity', $severity))
            ->when($category !== '', fn (Builder $q) => $q->where('category', $category))
            ->when($repo !== '', fn (Builder $q) => $q->whereHas('review', fn (Builder $r) => $r->where('repo', $repo)))
            ->when($scope !== '', fn (Builder $q) => $q->whereHas('review', fn (Builder $r) => $r->where('review_scope', $scope)))
            ->when($reviewer !== '', fn (Builder $q) => $q->whereHas('reactions', fn (Builder $r) => $r->where('github_user_login', $reviewer)))
            ->when($reactions, fn (Builder $q) => $q->where(fn (Builder $qq) => $qq->where('thumbs_up', '>', 0)->orWhere('thumbs_down', '>', 0)));

        if ($sort === 'submitted_at') {
            $query->leftJoin('pr_reviews', 'pr_review_comments.pr_review_id', '=', 'pr_reviews.id')
                ->orderBy('pr_reviews.submitted_at', $dir)
                ->select('pr_review_comments.*');
        } else {
            $query->orderBy($sort, $dir);
        }

        /** @var LengthAwarePaginator<int, PrReviewComment> $comments */
        $comments = $query->paginate(50);

        return $comments->through(fn (PrReviewComment $comment): array => [
            'id' => $comment->id,
            'repoSlug' => $comment->review?->repo,
            'prNumber' => $comment->review?->pr_number,
            'prUrl' => $comment->review?->pr_url,
            'filePath' => $comment->file_path,
            'lineNumber' => $comment->line_number,
            'severity' => $comment->severity,
            'category' => $comment->category,
            'thumbsUp' => $comment->thumbs_up,
            'thumbsDown' => $comment->thumbs_down,
            'bodyHtml' => Markdown::toHtml($comment->body),
        ]);
    }

    /**
     * @return array{reviews: int, suggestions: int, thumbsUpRate: float, mostDownvotedCategory: ?string}
     */
    private function stats(): array
    {
        return [
            'reviews' => PrReview::count(),
            'suggestions' => PrReviewComment::where('is_suggestion', true)->count(),
            'thumbsUpRate' => $this->computeThumbsUpRate(),
            'mostDownvotedCategory' => PrReviewComment::where('thumbs_down', '>', 0)
                ->selectRaw('category, SUM(thumbs_down) as total')
                ->groupBy('category')
                ->orderByDesc('total')
                ->value('category'),
        ];
    }

    /**
     * @return Collection<int, array{login: string, total: int, up: int, down: int}>
     */
    private function reviewerStats(): Collection
    {
        return PrReviewCommentReaction::query()
            ->selectRaw('github_user_login, COUNT(*) as total, SUM(CASE WHEN content = \'+1\' THEN 1 ELSE 0 END) as up, SUM(CASE WHEN content = \'-1\' THEN 1 ELSE 0 END) as down')
            ->groupBy('github_user_login')
            ->get()
            ->map(fn ($row): array => [
                'login' => $row->github_user_login,
                'total' => (int) $row->total,
                'up' => (int) $row->up,
                'down' => (int) $row->down,
            ]);
    }

    private function computeThumbsUpRate(): float
    {
        $total = PrReviewComment::where(fn (Builder $q) => $q->where('thumbs_up', '>', 0)->orWhere('thumbs_down', '>', 0))->count();

        if ($total === 0) {
            return 0.0;
        }

        $positive = PrReviewComment::where('thumbs_up', '>', 0)->whereColumn('thumbs_up', '>=', 'thumbs_down')->count();

        return round(($positive / $total) * 100, 1);
    }

    /**
     * @return array<int, string>
     */
    private function distinctRepos(): array
    {
        return PrReview::query()->distinct()->pluck('repo')->sort()->values()->all();
    }

    /**
     * @return array<int, string>
     */
    private function distinctCategories(): array
    {
        return PrReviewComment::query()->distinct()->pluck('category')->sort()->values()->all();
    }

    /**
     * @return array<int, string>
     */
    private function distinctReviewers(): array
    {
        return PrReviewCommentReaction::query()->distinct()->pluck('github_user_login')->sort()->values()->all();
    }
}
