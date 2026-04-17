<?php

namespace App\Livewire;

use App\Models\PrReview;
use App\Models\PrReviewComment;
use App\Models\PrReviewCommentReaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('PR Reviews')]
class PrReviewFeedback extends Component
{
    use WithPagination;

    public string $repo_filter = '';

    public string $severity_filter = '';

    public string $category_filter = '';

    public string $scope_filter = '';

    public string $reviewer_filter = '';

    public bool $has_reactions_only = false;

    public string $sort_by = 'submitted_at';

    public string $sort_dir = 'desc';

    public string $active_tab = 'all';

    /**
     * @return LengthAwarePaginator<int, PrReviewComment>
     */
    public function comments(): LengthAwarePaginator
    {
        $query = PrReviewComment::query()
            ->with(['review', 'reactions'])
            ->when($this->severity_filter !== '', fn ($q) => $q->where('severity', $this->severity_filter))
            ->when($this->category_filter !== '', fn ($q) => $q->where('category', $this->category_filter))
            ->when($this->repo_filter !== '', fn ($q) => $q->whereHas('review', fn ($r) => $r->where('repo', $this->repo_filter)))
            ->when($this->scope_filter !== '', fn ($q) => $q->whereHas('review', fn ($r) => $r->where('review_scope', $this->scope_filter)))
            ->when($this->reviewer_filter !== '', fn ($q) => $q->whereHas('reactions', fn ($r) => $r->where('github_user_login', $this->reviewer_filter)))
            ->when($this->has_reactions_only, fn ($q) => $q->where(fn ($qq) => $qq->where('thumbs_up', '>', 0)->orWhere('thumbs_down', '>', 0)));

        if ($this->sort_by === 'submitted_at') {
            $query->leftJoin('pr_reviews', 'pr_review_comments.pr_review_id', '=', 'pr_reviews.id')
                ->orderBy('pr_reviews.submitted_at', $this->sort_dir)
                ->select('pr_review_comments.*');
        } else {
            $query->orderBy($this->sort_by, $this->sort_dir);
        }

        return $query->paginate(50);
    }

    /**
     * @return array{reviews: int, suggestions: int, thumbs_up_rate: float, most_downvoted_category: ?string}
     */
    public function stats(): array
    {
        return [
            'reviews' => PrReview::count(),
            'suggestions' => PrReviewComment::where('is_suggestion', true)->count(),
            'thumbs_up_rate' => $this->computeThumbsUpRate(),
            'most_downvoted_category' => PrReviewComment::where('thumbs_down', '>', 0)
                ->selectRaw('category, SUM(thumbs_down) as total')
                ->groupBy('category')
                ->orderByDesc('total')
                ->value('category'),
        ];
    }

    /**
     * @return Collection<int, PrReviewCommentReaction>
     */
    public function reviewerStats(): Collection
    {
        return PrReviewCommentReaction::query()
            ->selectRaw('github_user_login, COUNT(*) as total, SUM(CASE WHEN content = \'+1\' THEN 1 ELSE 0 END) as up, SUM(CASE WHEN content = \'-1\' THEN 1 ELSE 0 END) as down')
            ->groupBy('github_user_login')
            ->get();
    }

    public function showIntro(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->has_seen_pr_review_intro_at === null;
    }

    public function dismissIntro(): void
    {
        $user = auth()->user();

        if ($user === null) {
            return;
        }

        $user->forceFill(['has_seen_pr_review_intro_at' => now()])->save();
    }

    public function sortBy(string $column): void
    {
        if ($this->sort_by === $column) {
            $this->sort_dir = $this->sort_dir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort_by = $column;
            $this->sort_dir = 'desc';
        }
    }

    public function render(): View
    {
        return view('livewire.pr-review-feedback', [
            'comments' => $this->comments(),
            'stats' => $this->stats(),
            'reviewerStats' => $this->reviewerStats(),
        ]);
    }

    private function computeThumbsUpRate(): float
    {
        $total = PrReviewComment::where(fn ($q) => $q->where('thumbs_up', '>', 0)->orWhere('thumbs_down', '>', 0))->count();

        if ($total === 0) {
            return 0.0;
        }

        $positive = PrReviewComment::where('thumbs_up', '>', 0)->whereColumn('thumbs_up', '>=', 'thumbs_down')->count();

        return round(($positive / $total) * 100, 1);
    }
}
