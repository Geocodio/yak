<?php

namespace App\Http\Resources;

use App\Channels\GitHub\AppService;
use App\DataTransferObjects\ConfigSnapshot;
use App\DataTransferObjects\RepositorySettings;
use App\Models\PrReview;
use App\Models\Repository;

/**
 * Shapes the review approvals settings page. The docblocks on each method are
 * the source of truth for the TypeScript `ApprovalsPage` type.
 */
final class ApprovalSettingsPageData
{
    /** @var array<int, string> */
    public const CODENAMES = [
        1 => 'COCKED PISTOL',
        2 => 'FAST PACE',
        3 => 'ROUND HOUSE',
        4 => 'DOUBLE TAKE',
        5 => 'FADE OUT',
    ];

    public const SHADOW_DAYS = 14;

    private const CONCERNS_PATH = '.yak/risk-profile.yml';

    private const CONCERNS_BRANCH = 'yak/risk-profile';

    private const CONCERN_HOLD_PREFIXES = ['Always needs a human: ', 'Touches '];

    /**
     * @return array{slug: string, name: string, defaultBranch: string, githubUrl: ?string, editUrl: string, updateUrl: string, draftConcernsUrl: string}
     */
    public static function repository(Repository $repository): array
    {
        return [
            'slug' => $repository->slug,
            'name' => $repository->name,
            'defaultBranch' => $repository->default_branch,
            'githubUrl' => $repository->githubUrl(),
            'editUrl' => route('repos.edit', $repository),
            'updateUrl' => route('repos.approvals.update', $repository),
            'draftConcernsUrl' => route('repos.risk-profile', $repository),
        ];
    }

    /**
     * `mode` is the stored mode, `shadow` at level 1. `changedBy` is null for a
     * change made from the command line or by a since-deleted user; `changedAt`
     * is null when the level was never changed.
     *
     * @return array{level: int, mode: 'shadow'|'enforce', changedBy: ?string, changedAt: ?string}
     */
    public static function approval(Repository $repository): array
    {
        $level = $repository->approvalLevel();

        return [
            'level' => $level,
            'mode' => $level > 1 && $repository->approvalMode() === 'enforce' ? 'enforce' : 'shadow',
            'changedBy' => $repository->approvalChangedBy?->name,
            'changedAt' => $repository->approval_changed_at?->toIso8601String(),
        ];
    }

    /**
     * `missing` covers no file and a version 1 file; `invalid` is a file that
     * exists with an error.
     *
     * @return array{state: 'ready'|'missing'|'invalid', items: list<array{name: string, approval: 'always_human'|'when_tested', description: string}>, fileUrl: ?string, editFileUrl: ?string}
     */
    public static function concerns(Repository $repository, ConfigSnapshot $snapshot): array
    {
        $items = (new RepositorySettings($repository, $snapshot))->concerns();
        $file = $snapshot->file('risk-profile.yml');
        $githubUrl = $repository->githubUrl();
        $branch = implode('/', array_map('rawurlencode', explode('/', $repository->default_branch)));

        return [
            'state' => match (true) {
                $items !== null => 'ready',
                $file !== null && $file->error !== null => 'invalid',
                default => 'missing',
            },
            'items' => $items ?? [],
            'fileUrl' => $githubUrl !== null ? "{$githubUrl}/blob/{$branch}/" . self::CONCERNS_PATH : null,
            'editFileUrl' => $githubUrl !== null ? "{$githubUrl}/edit/{$branch}/" . self::CONCERNS_PATH : null,
        ];
    }

    /**
     * Counts reviews of the last {@see self::SHADOW_DAYS} days that ran in shadow
     * or enforce mode. `recent` lists the latest 10 would-be approvals, one per
     * pull request.
     *
     * @return array{days: int, reviews: int, wouldApprove: int, heldByConcern: int, firstRecordedAt: ?string, recent: list<array{number: ?int, title: ?string, url: string, createdAt: string}>}
     */
    public static function shadow(Repository $repository): array
    {
        $reviews = PrReview::where('repo', $repository->slug)
            ->where('created_at', '>=', now()->subDays(self::SHADOW_DAYS))
            ->whereNotNull('risk_assessment')
            ->latest('created_at')
            ->latest('id')
            ->get()
            ->filter(fn (PrReview $review): bool => in_array($review->risk_assessment['mode'] ?? null, ['shadow', 'enforce'], true));

        $wouldApprove = $reviews->filter(fn (PrReview $review): bool => ($review->risk_assessment['candidate'] ?? null) === 'APPROVE');

        $heldByConcern = $reviews->filter(function (PrReview $review): bool {
            foreach ((array) ($review->risk_assessment['reasons'] ?? []) as $reason) {
                foreach (self::CONCERN_HOLD_PREFIXES as $prefix) {
                    if (is_string($reason) && str_starts_with($reason, $prefix)) {
                        return true;
                    }
                }
            }

            return false;
        });

        return [
            'days' => self::SHADOW_DAYS,
            'reviews' => $reviews->count(),
            'wouldApprove' => $wouldApprove->count(),
            'heldByConcern' => $heldByConcern->count(),
            'firstRecordedAt' => $reviews->last()?->created_at?->toIso8601String(),
            'recent' => array_values($wouldApprove
                ->unique('pr_url')
                ->take(10)
                ->map(fn (PrReview $review): array => [
                    'number' => (int) $review->pr_number,
                    'title' => null,
                    'url' => (string) $review->pr_url,
                    'createdAt' => $review->created_at?->toIso8601String() ?? '',
                ])
                ->all()),
        ];
    }

    /**
     * `unknown` when GitHub could not be asked; the open concerns pull request
     * is null when there is none or the lookup failed.
     *
     * @return array{requiredChecks: 'present'|'missing'|'unknown', concernsPullRequest: array{number: int, url: string}|null}
     */
    public static function github(Repository $repository): array
    {
        $github = app(AppService::class);
        $installationId = (int) config('yak.channels.github.installation_id');

        try {
            $requiredChecks = $github->hasRequiredStatusChecks($installationId, $repository->github_full_name, $repository->default_branch) ? 'present' : 'missing';
        } catch (\Throwable $exception) {
            report($exception);
            $requiredChecks = 'unknown';
        }

        $concernsPullRequest = null;

        try {
            $pullRequest = $github->findOpenPullRequestForBranch($installationId, $repository->github_full_name, self::CONCERNS_BRANCH);

            if ($pullRequest !== null) {
                $concernsPullRequest = ['number' => (int) $pullRequest['number'], 'url' => (string) ($pullRequest['html_url'] ?? '')];
            }
        } catch (\Throwable $exception) {
            report($exception);
        }

        return ['requiredChecks' => $requiredChecks, 'concernsPullRequest' => $concernsPullRequest];
    }
}
