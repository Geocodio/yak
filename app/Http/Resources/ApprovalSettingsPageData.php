<?php

namespace App\Http\Resources;

use App\Actions\EnqueuePrReview;
use App\Channels\GitHub\AppService;
use App\DataTransferObjects\ConfigSnapshot;
use App\DataTransferObjects\RepositorySettings;
use App\Enums\TaskMode;
use App\Jobs\CheckOpenPullRequestApprovalsJob;
use App\Jobs\CheckPullRequestApprovalJob;
use App\Models\ApprovalPreview;
use App\Models\PrReview;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\ReviewApprovalPolicy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

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
     * @return array{slug: string, name: string, defaultBranch: string, githubUrl: ?string, editUrl: string, updateUrl: string, draftConcernsUrl: string, checkUrl: string}
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
            'checkUrl' => route('repos.approvals.check', $repository),
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
     * or enforce mode at the repository's live level. `recent` lists the latest 10 would-be approvals, one per
     * pull request.
     *
     * @return array{days: int, reviews: int, wouldApprove: int, heldByConcern: int, firstRecordedAt: ?string, recent: list<array{number: ?int, title: ?string, url: string, createdAt: string}>}
     */
    public static function shadow(Repository $repository): array
    {
        $liveLevel = $repository->approvalLevel();

        $reviews = PrReview::where('repo', $repository->slug)
            ->where('created_at', '>=', now()->subDays(self::SHADOW_DAYS))
            ->whereNotNull('risk_assessment')
            ->latest('created_at')
            ->latest('id')
            ->get(['id', 'repo', 'pr_url', 'pr_number', 'risk_assessment', 'created_at'])
            ->filter(fn (PrReview $review): bool => in_array($review->risk_assessment['mode'] ?? null, ['shadow', 'enforce'], true)
                && ($review->risk_assessment['level'] ?? null) === $liveLevel);

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
     * The dry-run approval check of every open pull request. `checkedAt` is when
     * the last check finished and is null with no rows when the repository was
     * never checked. `pending` counts rows still being checked; the page polls
     * with `only: ['openPullRequests']` until it is 0. A row's `holds` are the
     * reasons it needs a human at every DEFCON level, without the repository-wide
     * ones in `repositoryHolds`. `waiting` means Yak has not reviewed the current
     * head commit yet; `reviewing` is a waiting row whose review of that commit is
     * queued or running. A waiting row whose review has finished is checked again
     * here, without the user asking. `changeKind` and `hasTestChange` let the page decide per
     * level; `changeKind` is null when it was not classified. A row's `error` is set
     * only when `status` is `failed`. `reviewNowUrl` requests a review of that
     * pull request; `yakReviewUrl` is Yak's review page, null while `waiting`.
     * `isChecking` is true while a listing of open pull requests is queued or
     * running. The top-level `error` is why the last listing of open pull requests failed,
     * null when it succeeded or never ran.
     *
     * @return array{checkedAt: ?string, error: ?string, isChecking: bool, pending: int, rows: list<array{number: int, title: string, author: string, url: string, headSha: string, status: 'pending'|'done'|'failed', waiting: bool, reviewing: bool, holds: list<string>, changeKind: ?string, hasTestChange: bool, error: ?string, reviewNowUrl: string, yakReviewUrl: ?string}>}
     */
    public static function openPullRequests(Repository $repository): array
    {
        self::recheckFinishedReviews($repository);
        $previews = ApprovalPreview::where('repository_id', $repository->id)->orderBy('pr_number')->get();
        $reviewingKeys = self::reviewingKeys($previews);
        $checkError = Cache::get(self::checkErrorCacheKey($repository));

        $stuckBefore = now()->subSeconds(CheckOpenPullRequestApprovalsJob::STUCK_AFTER_SECONDS);
        $pending = 0;
        $rows = [];
        foreach ($previews as $preview) {
            $isStuck = $preview->status === 'pending' && $preview->updated_at !== null && $preview->updated_at->lt($stuckBefore);
            $status = $isStuck ? 'failed' : $preview->status;
            $pending += $status === 'pending' ? 1 : 0;
            $waiting = (bool) ($preview->result['waiting'] ?? false);
            $rows[] = [
                'number' => $preview->pr_number,
                'title' => $preview->title,
                'author' => $preview->author,
                'url' => $preview->url,
                'headSha' => $preview->head_sha,
                'status' => $status,
                'waiting' => $waiting,
                'reviewing' => $waiting && $status === 'done' && isset($reviewingKeys[$preview->url . '|' . $preview->head_sha]),
                'holds' => $preview->result['holds'] ?? [],
                'changeKind' => $preview->result['changeKind'] ?? null,
                'hasTestChange' => (bool) ($preview->result['hasTestChange'] ?? false),
                'error' => $status === 'failed' ? ($isStuck ? 'The check did not finish. Check again.' : $preview->error) : null,
                'reviewNowUrl' => route('repos.approvals.review', [$repository, $preview->pr_number]),
                'yakReviewUrl' => $waiting || $status !== 'done' ? null : route('pr-reviews.for-pr', [$repository->slug, $preview->pr_number]),
            ];
        }

        return [
            'checkedAt' => $repository->approval_checked_at?->toIso8601String(),
            'error' => is_string($checkError) ? $checkError : null,
            'isChecking' => Cache::has(self::checkRunningCacheKey($repository->id)),
            'pending' => $pending,
            'rows' => $rows,
        ];
    }

    /**
     * Sets a waiting row pending and queues its check once Yak has a review at its
     * head commit. The conditional update lets only one request claim a row.
     */
    private static function recheckFinishedReviews(Repository $repository): void
    {
        $waiting = ApprovalPreview::where('repository_id', $repository->id)->where('status', 'done')
            ->get(['id', 'pr_number', 'head_sha', 'result'])->filter(fn (ApprovalPreview $preview): bool => (bool) ($preview->result['waiting'] ?? false));
        if ($waiting->isEmpty()) {
            return;
        }

        $reviewed = PrReview::where('repo', $repository->slug)
            ->whereIn('pr_number', $waiting->pluck('pr_number'))
            ->whereIn('commit_sha_reviewed', $waiting->pluck('head_sha'))
            ->get(['pr_number', 'commit_sha_reviewed'])
            ->mapWithKeys(fn (PrReview $review): array => [$review->pr_number . '|' . $review->commit_sha_reviewed => true]);

        foreach ($waiting as $preview) {
            if (isset($reviewed[$preview->pr_number . '|' . $preview->head_sha])
                && ApprovalPreview::where('id', $preview->id)->where('status', 'done')->update(['status' => 'pending']) === 1) {
                CheckPullRequestApprovalJob::dispatch($preview->id);
            }
        }
    }

    /**
     * One query for every row: the pull request URL and head commit of each
     * queued or running review task, as `url|sha` keys.
     *
     * @param  Collection<int, ApprovalPreview>  $previews
     * @return array<string, true>
     */
    private static function reviewingKeys($previews): array
    {
        $headShas = $previews->pluck('head_sha', 'url');
        if ($headShas->isEmpty()) {
            return [];
        }

        $keys = [];
        $tasks = YakTask::where('mode', TaskMode::Review)->whereIn('external_id', $headShas->keys())
            ->whereIn('status', ['pending', 'running'])->get(['external_id', 'context']);
        foreach ($previews as $preview) {
            foreach ($tasks as $task) {
                if ($task->external_id === $preview->url && EnqueuePrReview::isAtHead($task, (string) $preview->head_sha)) {
                    $keys[$preview->url . '|' . $preview->head_sha] = true;
                }
            }
        }

        return $keys;
    }

    /**
     * Holds that block every pull request of the repository, such as a missing
     * concerns file or branch protection without required checks.
     *
     * @return list<string>
     */
    public static function repositoryHolds(Repository $repository): array
    {
        return app(ReviewApprovalPolicy::class)->repositoryHolds($repository);
    }

    public static function checkRunningCacheKey(int $repositoryId): string
    {
        return "approval-check-running:{$repositoryId}";
    }

    public static function checkErrorCacheKey(Repository $repository): string
    {
        return "approval-check-error:{$repository->id}";
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

        return ['requiredChecks' => $requiredChecks, 'concernsPullRequest' => self::concernsPullRequest($repository)];
    }

    /**
     * The open pull request that adds the concerns file, null when there is none or the lookup failed.
     *
     * @return array{number: int, url: string}|null
     */
    public static function concernsPullRequest(Repository $repository): ?array
    {
        try {
            $pullRequest = app(AppService::class)->findOpenPullRequestForBranch(
                (int) config('yak.channels.github.installation_id'),
                $repository->github_full_name,
                self::CONCERNS_BRANCH,
            );

            return $pullRequest === null ? null : ['number' => (int) $pullRequest['number'], 'url' => (string) ($pullRequest['html_url'] ?? '')];
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }
    }
}
