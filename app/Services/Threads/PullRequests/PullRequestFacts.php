<?php

namespace App\Services\Threads\PullRequests;

use App\Channels\GitHub\AppService;
use App\Models\Repository;
use App\Models\ThreadRepository;
use Illuminate\Support\Collection;

/**
 * Writes the pull request facts the review queue reads (draft flag, head
 * commit, opened time, and each reviewer's latest verdict) onto the thread
 * repository rows a GitHub webhook belongs to.
 */
class PullRequestFacts
{
    public function __construct(private AppService $appService) {}

    /**
     * @return Collection<int, ThreadRepository>
     */
    public function rowsFor(string $repoFullName, int $prNumber): Collection
    {
        $slug = Repository::resolveFromGitHub(null, $repoFullName)?->slug;

        if ($slug === null) {
            return new Collection;
        }

        return ThreadRepository::query()->where('repo', $slug)->where('pull_request_number', $prNumber)->get();
    }

    /**
     * @param  array<string, mixed>  $pullRequest
     */
    public function recordPullRequest(array $pullRequest, string $repoFullName): void
    {
        $number = (int) ($pullRequest['number'] ?? 0);

        foreach ($this->rowsFor($repoFullName, $number) as $row) {
            $row->pull_request_is_draft = (bool) ($pullRequest['draft'] ?? false);
            $row->pull_request_head_sha = $pullRequest['head']['sha'] ?? $row->pull_request_head_sha;

            if ($row->pull_request_opened_at === null && isset($pullRequest['created_at'])) {
                $row->pull_request_opened_at = $pullRequest['created_at'];
            }

            $row->save();
        }
    }

    /**
     * @param  array<string, mixed>  $review
     */
    public function recordReview(array $review, string $action, string $repoFullName, int $prNumber): void
    {
        $login = strtolower((string) ($review['user']['login'] ?? ''));

        if ($login === '' || strcasecmp($login, $this->appService->appBotLogin()) === 0) {
            return;
        }

        $incoming = $action === 'dismissed' ? 'dismissed' : strtolower((string) ($review['state'] ?? ''));

        foreach ($this->rowsFor($repoFullName, $prNumber) as $row) {
            $reviews = $row->pull_request_reviews ?? [];
            $reviews[$login] = [
                'state' => self::mergeReview($reviews[$login] ?? null, $incoming),
                'commitSha' => $review['commit_id'] ?? null,
            ];
            $row->pull_request_reviews = $reviews;
            $row->save();
        }
    }

    /**
     * A comment never replaces an approval or a change request.
     *
     * @param  array{state: string, commitSha: string|null}|null  $existing
     */
    public static function mergeReview(?array $existing, string $state): string
    {
        if ($state === 'commented' && in_array($existing['state'] ?? null, ['approved', 'changes_requested'], true)) {
            return $existing['state'];
        }

        return $state;
    }
}
