<?php

namespace App\Console\Commands;

use App\Channels\GitHub\AppService;
use App\Models\PrReview;
use App\Models\Repository;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Measures whether review approvals are safe to enforce. Shadow mode records
 * what Yak would have done; this compares each would-be approval with what
 * humans did next. A human change request or a new commit after the reviewed
 * commit means Yak would have approved a pull request that still needed work.
 */
#[Signature('yak:approval-report {--days=14 : How far back to look} {--repo=* : Limit to these repository slugs}')]
#[Description('Compare shadow review approvals with what humans did on the same pull requests')]
class ApprovalReportCommand extends Command
{
    public function handle(AppService $github): int
    {
        /** @var array<int, string> $repos */
        $repos = (array) $this->option('repo');

        $reviews = PrReview::query()
            ->whereNotNull('risk_assessment')
            ->where('created_at', '>=', now()->subDays((int) $this->option('days')))
            ->when($repos !== [], fn ($query) => $query->whereIn('repo', $repos))
            ->orderBy('id')
            ->get()
            ->filter(fn (PrReview $review): bool => in_array($review->risk_assessment['mode'] ?? null, ['shadow', 'enforce'], true));

        if ($reviews->isEmpty()) {
            $this->components->info('No shadow or enforced review decisions in this window.');

            return self::SUCCESS;
        }

        $this->table(['Repository', 'Reviews', 'Approve', 'Request changes', 'Comment'], $reviews
            ->groupBy('repo')
            ->map(fn (Collection $group, string $repo): array => [
                $repo,
                $group->count(),
                $group->where('risk_assessment.candidate', 'APPROVE')->count(),
                $group->where('risk_assessment.candidate', 'REQUEST_CHANGES')->count(),
                $group->where('risk_assessment.candidate', 'COMMENT')->count(),
            ])->values()->all());

        $this->table(['Top reasons Yak held a review for a human', 'Reviews'], $reviews
            ->flatMap(fn (PrReview $review): array => array_values(array_unique(array_map(
                fn (string $reason): string => (string) preg_replace('/: .*/s', '', $reason),
                (array) ($review->risk_assessment['reasons'] ?? []),
            ))))
            ->countBy()
            ->sortDesc()
            ->take(15)
            ->map(fn (int $count, string $reason): array => [$reason, $count])
            ->values()->all());

        $candidates = $reviews
            ->where('risk_assessment.candidate', 'APPROVE')
            ->keyBy('pr_url')
            ->values();

        $installationId = (int) config('yak.channels.github.installation_id');
        $botLogin = $github->appBotLogin();
        $rows = $candidates->map(fn (PrReview $review): array => $this->audit($github, $installationId, $botLogin, $review));

        $this->table(['Would-be approval', 'Outcome', 'Commits after review', 'Human changes requested'], $rows->all());

        $needsLook = $rows->filter(fn (array $row): bool => $row[1] === 'needs a look')->count();
        $this->components->info("{$candidates->count()} would-be approvals, {$needsLook} needed a human change after Yak's review.");

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function audit(AppService $github, int $installationId, string $botLogin, PrReview $review): array
    {
        try {
            $repo = Repository::githubNameFor($review->repo);
            $pr = $github->getPullRequest($installationId, $repo, $review->pr_number);
            $shas = array_column($github->listPullRequestCommits($installationId, $repo, $review->pr_number), 'sha');
            $position = array_search($review->commit_sha_reviewed, $shas, true);
            $commitsAfter = $position === false ? null : count($shas) - $position - 1;
            $changesRequested = collect($github->listPullRequestReviews($installationId, $repo, $review->pr_number))
                ->contains(fn (array $githubReview): bool => ($githubReview['state'] ?? '') === 'CHANGES_REQUESTED'
                    && strtotime((string) ($githubReview['submitted_at'] ?? '')) >= $review->created_at?->getTimestamp()
                    && strcasecmp((string) ($githubReview['user']['login'] ?? ''), $botLogin) !== 0);
        } catch (Throwable $exception) {
            return [$review->pr_url, 'unavailable', '-', '-'];
        }

        $outcome = match (true) {
            $changesRequested || $commitsAfter !== 0 => 'needs a look',
            ($pr['merged_at'] ?? null) !== null => 'clean merge',
            ($pr['state'] ?? '') === 'open' => 'open',
            default => 'closed unmerged',
        };

        return [$review->pr_url, $outcome, $commitsAfter === null ? 'reviewed commit gone' : (string) $commitsAfter, $changesRequested ? 'yes' : 'no'];
    }
}
