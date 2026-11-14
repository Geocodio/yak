<?php

namespace App\Services;

use App\Enums\RepositoryRole;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\Preview\ThreadPreviewPanelState;
use App\Services\Threads\ThreadList;
use Illuminate\Database\Eloquent\Builder;

/**
 * The command palette's search: threads, repositories and pull requests the
 * viewer may see. Runs a fixed number of queries whatever the row count.
 */
class PaletteSearch
{
    public const LIMIT = 10;

    private const MINIMUM_LENGTH = 2;

    public function __construct(private ThreadPreviewPanelState $previews) {}

    /**
     * @return array{threads: list<Thread>, repositories: list<Repository>, pullRequests: list<array{number: int, title: string, repo: string, url: string, threadId: ?string, reviewUrl: ?string}>}
     */
    public function search(User $user, string $query): array
    {
        $query = trim($query);

        if (mb_strlen($query) < self::MINIMUM_LENGTH) {
            return ['threads' => [], 'repositories' => [], 'pullRequests' => []];
        }

        $pattern = ThreadList::likePattern($query);
        $number = preg_match('/^#?(\d+)$/', $query, $matches) === 1 ? (int) $matches[1] : null;

        return [
            'threads' => $this->threads($user, $pattern),
            'repositories' => $this->repositories($pattern),
            'pullRequests' => $this->pullRequests($user, $pattern, $number),
        ];
    }

    public function previewUrl(Thread $thread): ?string
    {
        return $this->previews->loadedUrlFor($thread);
    }

    /**
     * @return list<Thread>
     */
    private function threads(User $user, string $pattern): array
    {
        $threads = Thread::query()
            ->withoutVisibilityScope()
            ->visibleTo($user)
            ->where(fn (Builder $match) => $match
                ->whereRaw("lower(threads.title) like ? escape '!'", [$pattern])
                ->orWhereRaw("lower(threads.repo) like ? escape '!'", [$pattern])
                ->orWhereRaw("lower(threads.branch) like ? escape '!'", [$pattern]))
            ->with(['repositories' => fn ($repositories) => $repositories->where('role', '!=', RepositoryRole::Context->value)->whereNotNull('pull_request_url')->orderBy('id')])
            ->orderByRaw("threads.status = 'open' desc")
            ->orderByRaw('threads.last_activity_at is null')
            ->orderByDesc('threads.last_activity_at')
            ->orderByDesc('threads.id')
            ->limit(self::LIMIT)
            ->get();

        return array_values($threads->all());
    }

    /**
     * @return list<Repository>
     */
    private function repositories(string $pattern): array
    {
        $repositories = Repository::query()
            ->where(fn (Builder $match) => $match
                ->whereRaw("lower(slug) like ? escape '!'", [$pattern])
                ->orWhereRaw("lower(name) like ? escape '!'", [$pattern]))
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();

        return array_values($repositories->all());
    }

    /**
     * Pull requests of visible threads, then reviewed pull requests, one row per pull request. Neither table
     * stores a pull request title, so a row matches on its number, repository or thread title.
     *
     * @return list<array{number: int, title: string, repo: string, url: string, threadId: ?string, reviewUrl: ?string}>
     */
    private function pullRequests(User $user, string $pattern, ?int $number): array
    {
        $rows = [];

        $threadPullRequests = Thread::query()
            ->withoutVisibilityScope()
            ->visibleTo($user)
            ->join('thread_repositories', 'thread_repositories.thread_id', '=', 'threads.id')
            ->where('thread_repositories.role', '!=', RepositoryRole::Context->value)
            ->whereNotNull('thread_repositories.pull_request_url')
            ->whereNotNull('thread_repositories.pull_request_number')
            ->where(fn (Builder $match) => $match
                ->when($number !== null, fn (Builder $query) => $query->where('thread_repositories.pull_request_number', $number))
                ->orWhereRaw("lower(threads.title) like ? escape '!'", [$pattern])
                ->orWhereRaw("lower(thread_repositories.repo) like ? escape '!'", [$pattern]))
            ->orderByDesc('threads.last_activity_at')
            ->orderBy('thread_repositories.id')
            ->limit(self::LIMIT * 5)
            ->get(['threads.public_id', 'threads.title', 'thread_repositories.thread_id', 'thread_repositories.repo', 'thread_repositories.pull_request_number', 'thread_repositories.pull_request_url']);

        foreach ($threadPullRequests->unique('thread_id') as $row) {
            $url = (string) $row->getAttribute('pull_request_url');
            $rows[$url] = [
                'number' => (int) $row->getAttribute('pull_request_number'),
                'title' => $row->title,
                'repo' => $row->repo,
                'url' => $url,
                'threadId' => $row->public_id,
                'reviewUrl' => null,
            ];
        }

        $reviews = PrReview::query()
            ->selectRaw('repo, pr_number, max(pr_url) as pr_url, max(submitted_at) as latest_review_at')
            ->where(fn (Builder $match) => $match
                ->when($number !== null, fn (Builder $query) => $query->where('pr_number', $number))
                ->orWhereRaw("lower(repo) like ? escape '!'", [$pattern]))
            ->groupBy('repo', 'pr_number')
            ->orderByDesc('latest_review_at')
            ->limit(self::LIMIT)
            ->get();

        foreach ($reviews as $review) {
            $reviewUrl = route('pr-reviews.for-pr', [$review->repo, $review->pr_number]);

            $url = (string) $review->pr_url;

            if (isset($rows[$url])) {
                $rows[$url]['reviewUrl'] = $reviewUrl;

                continue;
            }

            $rows[$url] = [
                'number' => (int) $review->pr_number,
                'title' => "{$review->repo} #{$review->pr_number}",
                'repo' => $review->repo,
                'url' => $url,
                'threadId' => null,
                'reviewUrl' => $reviewUrl,
            ];
        }

        return array_slice(array_values($rows), 0, self::LIMIT);
    }
}
