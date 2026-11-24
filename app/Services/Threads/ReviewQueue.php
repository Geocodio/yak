<?php

namespace App\Services\Threads;

use App\Enums\ReviewState;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * Where a thread with open pull requests sits in the viewer's review queue.
 */
class ReviewQueue
{
    /**
     * Null when the thread, with its loaded sub-threads, has no open pull request.
     *
     * @param  CarbonInterface|null  $latestDrivenRunStartedAt  when the viewer last drove a turn on the thread or a sub-thread
     * @return array{state: string, pullRequests: list<array{repo: string, number: int, url: string, isDraft: bool}>, blockedLabel: 'Conflicts'|'CI failing'|null, isCiPassing: bool, hasNewCommitsSinceApproval: bool, otherApprovals: int}|null
     */
    public static function summarize(Thread $thread, User $viewer, bool $isRunning, ?CarbonInterface $latestDrivenRunStartedAt): ?array
    {
        $pullRequests = $thread->repositories
            ->concat($thread->children->flatMap(fn (Thread $child) => $child->repositories))
            ->filter(fn (ThreadRepository $repository): bool => $repository->pull_request_state === 'open')
            ->values();

        if ($pullRequests->isEmpty()) {
            return null;
        }

        $login = $viewer->github_login === null ? null : strtolower($viewer->github_login);
        $viewerReviews = $pullRequests->map(fn (ThreadRepository $repository): ?array => $login === null ? null : ($repository->pull_request_reviews[$login] ?? null));
        $isApprovedOnHead = fn (ThreadRepository $repository, ?array $review): bool => $review !== null
            && $review['state'] === 'approved'
            && ($repository->pull_request_head_sha === null || $review['commitSha'] === $repository->pull_request_head_sha);

        $isApproved = $pullRequests->every(fn (ThreadRepository $repository, int $index): bool => $isApprovedOnHead($repository, $viewerReviews[$index]));
        $hasNewCommitsSinceApproval = ! $isApproved && $pullRequests->contains(fn (ThreadRepository $repository, int $index): bool => $viewerReviews[$index] !== null
            && $viewerReviews[$index]['state'] === 'approved'
            && ! $isApprovedOnHead($repository, $viewerReviews[$index]));

        $earliestOpenedAt = $pullRequests->map(fn (ThreadRepository $repository) => $repository->pull_request_opened_at)->filter()->min();
        $hasDrivenTurn = $latestDrivenRunStartedAt !== null && $earliestOpenedAt !== null && $latestDrivenRunStartedAt->greaterThanOrEqualTo($earliestOpenedAt);
        $hasReviewed = $hasDrivenTurn || $viewerReviews->contains(fn (?array $review): bool => $review !== null);

        $state = match (true) {
            $isApproved => ReviewState::Approved,
            $isRunning => ReviewState::WithYak,
            $hasReviewed => ReviewState::BackToYou,
            default => ReviewState::NotReviewed,
        };

        return [
            'state' => $state->value,
            'pullRequests' => $pullRequests->map(fn (ThreadRepository $repository): array => [
                'repo' => $repository->repo,
                'number' => (int) $repository->pull_request_number,
                'url' => (string) $repository->pull_request_url,
                'isDraft' => $repository->pull_request_is_draft,
            ])->all(),
            'blockedLabel' => match (true) {
                $pullRequests->contains(fn (ThreadRepository $repository): bool => $repository->pull_request_mergeable_state === 'dirty') => 'Conflicts',
                $pullRequests->contains(fn (ThreadRepository $repository): bool => $repository->ci_status === 'failing') => 'CI failing',
                default => null,
            },
            'isCiPassing' => $pullRequests->every(fn (ThreadRepository $repository): bool => $repository->ci_status === 'passing'),
            'hasNewCommitsSinceApproval' => $hasNewCommitsSinceApproval,
            'otherApprovals' => $pullRequests
                ->flatMap(fn (ThreadRepository $repository): array => collect($repository->pull_request_reviews ?? [])
                    ->filter(fn (array $review, string $reviewer): bool => $review['state'] === 'approved' && $reviewer !== $login)
                    ->keys()->all())
                ->unique()->count(),
        ];
    }
}
