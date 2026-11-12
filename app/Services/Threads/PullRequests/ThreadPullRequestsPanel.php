<?php

namespace App\Services\Threads\PullRequests;

use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadDiffRepository;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Services\Threads\ThreadAccess;
use Illuminate\Support\Str;

/**
 * The read model of the Pull requests panel. The array shape below is the
 * source of truth for `resources/js/types/thread-pull-requests.ts`.
 */
class ThreadPullRequestsPanel
{
    public function __construct(
        private readonly MergeOrder $mergeOrder,
        private readonly SiblingBlock $siblingBlock,
        private readonly ThreadAccess $access,
    ) {}

    /**
     * @return array{
     *     state: 'none'|'waiting_for_ci'|'blocked'|'ready'|'partial'|'done',
     *     banner: array{title: string, body: string, tone: 'info'|'danger'|'success'},
     *     openCount: int,
     *     totalCount: int,
     *     canDrive: bool,
     *     pullRequests: list<array{rowId: int, repo: string, repoInitials: string, role: string, number: ?int, url: string, title: string, state: 'open'|'merged'|'closed', closedAt: ?string, ci: ?array{status: string, passedChecks: int, totalChecks: int, checks: list<array{name: string, state: string, durationSeconds: ?int, excerpt: ?string}>}, mergeAfter: ?array{repo: string, number: ?int, reason: ?string}, siblingBlock: ?string, canAskToFix: bool}>,
     *     withoutPullRequest: list<array{repo: string, role: string, reason: 'context'|'unchanged'|'pending', note: string}>,
     *     expectations: list<string>,
     * }
     */
    public function build(Thread $thread, ?User $viewer): array
    {
        $canDrive = $this->access->canDrive($viewer, $thread);
        $rows = $this->mergeOrder->forThread($thread)->values();
        $openCount = $rows->filter(fn (ThreadRepository $row): bool => $row->hasOpenPullRequest())->count();
        $pullRequests = [];
        $previousOpen = null;

        foreach ($rows as $row) {
            $isOpen = $row->hasOpenPullRequest();

            $pullRequests[] = [
                'rowId' => $row->id,
                'repo' => Repository::githubNameFor($row->repo),
                'repoInitials' => self::initials($row->repo),
                'role' => $row->role->value,
                'number' => $row->pull_request_number,
                'url' => (string) $row->pull_request_url,
                'title' => (string) $row->pull_request_title,
                'state' => $row->pull_request_state === 'merged' || $row->pull_request_state === 'closed' ? $row->pull_request_state : 'open',
                'closedAt' => $row->pull_request_closed_at?->toIso8601String(),
                'ci' => $this->ci($row),
                'mergeAfter' => $isOpen && $previousOpen !== null ? [
                    'repo' => Repository::githubNameFor($previousOpen->repo),
                    'number' => $previousOpen->pull_request_number,
                    'reason' => $row->merge_reason,
                ] : null,
                'siblingBlock' => $this->siblingBlock->render($thread, $row),
                'canAskToFix' => $canDrive && $isOpen && $row->ci_status === 'failing',
            ];

            $previousOpen = $isOpen ? $row : $previousOpen;
        }

        $state = $this->state($rows->all(), $openCount);
        $failing = $rows->first(fn (ThreadRepository $row): bool => $row->hasOpenPullRequest() && $row->ci_status === 'failing');

        return [
            'state' => $state,
            'banner' => $this->banner($state, $failing, $rows->count() - $openCount, $rows->count(), $rows->filter(fn (ThreadRepository $row): bool => $row->hasOpenPullRequest())->values()->all()),
            'openCount' => $openCount,
            'totalCount' => $rows->count(),
            'canDrive' => $canDrive,
            'pullRequests' => $pullRequests,
            'withoutPullRequest' => $this->withoutPullRequest($thread),
            'expectations' => $this->expectations($thread),
        ];
    }

    /**
     * @param  array<int, ThreadRepository>  $rows
     * @return 'none'|'waiting_for_ci'|'blocked'|'ready'|'partial'|'done'
     */
    private function state(array $rows, int $openCount): string
    {
        if ($rows === []) {
            return 'none';
        }

        if ($openCount === 0) {
            return 'done';
        }

        $open = array_filter($rows, fn (ThreadRepository $row): bool => $row->hasOpenPullRequest());

        if (array_filter($open, fn (ThreadRepository $row): bool => $row->ci_status === 'failing') !== []) {
            return 'blocked';
        }

        if ($openCount < count($rows)) {
            return 'partial';
        }

        if (array_filter($open, fn (ThreadRepository $row): bool => $row->ci_status === 'running') !== []) {
            return 'waiting_for_ci';
        }

        return 'ready';
    }

    /**
     * @param  array<int, ThreadRepository>  $openRows
     * @return array{title: string, body: string, tone: 'info'|'danger'|'success'}
     */
    private function banner(string $state, ?ThreadRepository $failing, int $endedCount, int $totalCount, array $openRows): array
    {
        return match ($state) {
            'none' => ['title' => 'No pull requests yet', 'body' => 'Claude opens one pull request for each repository it changes.', 'tone' => 'info'],
            'waiting_for_ci' => ['title' => 'Waiting for CI', 'body' => 'One pull request is still running checks. Merge in the order below once they pass.', 'tone' => 'info'],
            'blocked' => [
                'title' => 'Blocked: CI is failing in ' . Repository::githubNameFor((string) $failing?->repo),
                'body' => 'Fix it before merging in order. The other pull request does not depend on this check, but merging half of a change leaves ' . $this->outOfStepPair($failing, $openRows) . ' out of step.',
                'tone' => 'danger',
            ],
            'partial' => ['title' => 'Partly merged', 'body' => "{$endedCount} of {$totalCount} pull requests are done. Merge the rest in the order below.", 'tone' => 'info'],
            'ready' => ['title' => 'Ready to merge', 'body' => 'Checks pass. Merge in the order below.', 'tone' => 'success'],
            default => ['title' => 'All pull requests are done', 'body' => 'Every pull request of this thread has been merged or closed.', 'tone' => 'success'],
        };
    }

    /**
     * @param  array<int, ThreadRepository>  $openRows
     */
    private function outOfStepPair(?ThreadRepository $failing, array $openRows): string
    {
        $others = array_values(array_filter($openRows, fn (ThreadRepository $row): bool => $row->id !== $failing?->id));

        if ($failing === null || count($openRows) !== 2 || count($others) !== 1) {
            return 'the repositories';
        }

        return 'the ' . $failing->shortName() . ' and the ' . $others[0]->shortName();
    }

    /**
     * What the person can expect from each repository of the thread.
     *
     * @return list<string>
     */
    private function expectations(Thread $thread): array
    {
        $lines = [];
        $hasOpened = false;

        foreach (ThreadRepository::query()->where('thread_id', $thread->id)->orderBy('id')->get() as $row) {
            if (! $row->getsPullRequest()) {
                $lines[] = "{$row->repo} gets none, it is context";

                continue;
            }

            $lines[] = $hasOpened ? "{$row->repo} waits for it" : "{$row->repo} opens first";
            $hasOpened = true;
        }

        return $lines;
    }

    /**
     * @return ?array{status: string, passedChecks: int, totalChecks: int, checks: list<array{name: string, state: string, durationSeconds: ?int, excerpt: ?string}>}
     */
    private function ci(ThreadRepository $row): ?array
    {
        if ($row->ci_status === null) {
            return null;
        }

        $checks = [];

        foreach ($row->ci_checks ?? [] as $check) {
            $checks[] = [
                'name' => $check['name'],
                'state' => $check['state'],
                'durationSeconds' => $check['durationSeconds'] ?? null,
                'excerpt' => $check['excerpt'] ?? null,
            ];
        }

        return [
            'status' => $row->ci_status,
            'passedChecks' => count(array_filter($checks, fn (array $check): bool => $check['state'] === 'passing')),
            'totalChecks' => count($checks),
            'checks' => $checks,
        ];
    }

    /**
     * @return list<array{repo: string, role: string, reason: 'context'|'unchanged'|'pending', note: string}>
     */
    private function withoutPullRequest(Thread $thread): array
    {
        $listed = [];

        $rows = ThreadRepository::query()->where('thread_id', $thread->id)->whereNull('pull_request_url')->orderBy('id')->get();
        $scratchCounts = ThreadDiffRepository::query()
            ->whereHas('diff', fn ($query) => $query->where('thread_id', $thread->id))
            ->pluck('files_count', 'repo');

        foreach ($rows as $row) {
            $reason = ! $row->isChangesRepo() ? 'context' : ($row->branch !== null || $row->ci_status !== null ? 'pending' : 'unchanged');

            $listed[] = [
                'repo' => Repository::githubNameFor($row->repo),
                'role' => $row->role->value,
                'reason' => $reason,
                'note' => match ($reason) {
                    'context' => ($scratchCounts[$row->repo] ?? 0) > 0
                        ? "No pull request. Claude left {$scratchCounts[$row->repo]} scratch files there, and they go away with the sandbox."
                        : 'No pull request.',
                    'pending' => 'The pull request opens once CI passes.',
                    default => 'No commits here, so no pull request.',
                },
            ];
        }

        return $listed;
    }

    private static function initials(string $slug): string
    {
        return Str::upper(Str::substr(Str::afterLast($slug, '/'), 0, 2));
    }
}
