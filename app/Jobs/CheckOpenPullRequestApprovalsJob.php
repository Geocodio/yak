<?php

namespace App\Jobs;

use App\Channels\GitHub\AppService;
use App\Http\Resources\ApprovalSettingsPageData;
use App\Models\ApprovalPreview;
use App\Models\PrReview;
use App\Models\Repository;
use App\Services\ReviewApprovalPolicy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Lists a repository's open pull requests and queues one approval check for
 * each one that is new, pushed to, or checked against an older concerns file.
 */
class CheckOpenPullRequestApprovalsJob implements ShouldQueue
{
    use Queueable;

    public const LISTING_ERROR = 'Could not list open pull requests on GitHub.';

    /** A pending row older than this is treated as stuck and checked again. */
    public const STUCK_AFTER_SECONDS = 300;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $repositoryId)
    {
        $this->onQueue('default');
    }

    /**
     * Everything a stored result depends on besides the head commit and the
     * concerns file: the latest Yak review at the head, the draft and auto-merge
     * state, the base branch with its protection holds, and the path excludes.
     * A change in any of them means the stored result may be out of date.
     *
     * @param  array<string, mixed>  $pullRequest
     * @param  list<string>  $baseHolds
     */
    public static function fingerprint(Repository $repository, array $pullRequest, ?int $reviewId, array $baseHolds): string
    {
        return md5((string) json_encode([
            $reviewId,
            (bool) ($pullRequest['draft'] ?? false),
            ($pullRequest['auto_merge'] ?? null) !== null,
            (string) ($pullRequest['base']['ref'] ?? ''),
            $repository->settings()->reviewPathExcludes() ?? (array) config('yak.pr_review.default_path_excludes', []),
            $baseHolds,
        ]));
    }

    public function failed(\Throwable $exception): void
    {
        Cache::forget(ApprovalSettingsPageData::checkRunningCacheKey($this->repositoryId));
    }

    public function handle(AppService $github): void
    {
        try {
            $this->check($github);
        } finally {
            Cache::forget(ApprovalSettingsPageData::checkRunningCacheKey($this->repositoryId));
        }
    }

    private function check(AppService $github): void
    {
        $repository = Repository::find($this->repositoryId);
        if ($repository === null) {
            return;
        }

        try {
            $pullRequests = $github->listOpenPullRequests((int) config('yak.channels.github.installation_id'), $repository->github_full_name, orFail: true);
        } catch (\Throwable $exception) {
            report($exception);
            Cache::forever(ApprovalSettingsPageData::checkErrorCacheKey($repository), self::LISTING_ERROR);

            return;
        }
        $concernsVersion = $repository->settings()->concernsVersion();
        $openNumbers = [];
        $policy = app(ReviewApprovalPolicy::class);
        $baseHolds = [];

        foreach ($pullRequests as $pullRequest) {
            $number = (int) $pullRequest['number'];
            $headSha = (string) ($pullRequest['head']['sha'] ?? '');
            $openNumbers[] = $number;

            $baseRef = (string) ($pullRequest['base']['ref'] ?? $repository->default_branch);
            $baseHolds[$baseRef] ??= $policy->branchHolds($repository, $baseRef, fresh: true);
            $reviewId = PrReview::where('repo', $repository->slug)->where('pr_number', $number)->where('commit_sha_reviewed', $headSha)->max('id');
            $fingerprint = self::fingerprint($repository, $pullRequest, $reviewId === null ? null : (int) $reviewId, $baseHolds[$baseRef]);

            $preview = ApprovalPreview::firstOrNew(['repository_id' => $repository->id, 'pr_number' => $number]);
            $preview->fill([
                'title' => (string) ($pullRequest['title'] ?? ''),
                'author' => (string) ($pullRequest['user']['login'] ?? ''),
                'url' => (string) ($pullRequest['html_url'] ?? ''),
            ]);

            $isSame = $preview->exists && $preview->head_sha === $headSha && $preview->concerns_version === $concernsVersion && $preview->fingerprint === $fingerprint;
            $result = $preview->result ?? [];
            $isSettled = ($result['changeKind'] ?? null) !== null || ($result['holds'] ?? []) !== [];
            $isCurrent = $isSame && $preview->status === 'done' && ! ($result['waiting'] ?? false) && $isSettled;
            $isInFlight = $isSame && $preview->status === 'pending' && $preview->updated_at !== null && $preview->updated_at->gt(now()->subSeconds(self::STUCK_AFTER_SECONDS));
            if ($isCurrent || $isInFlight) {
                $preview->save();

                continue;
            }

            $preview->fill(['head_sha' => $headSha, 'status' => 'pending', 'concerns_version' => $concernsVersion, 'fingerprint' => $fingerprint, 'result' => null, 'error' => null])->save();
            CheckPullRequestApprovalJob::dispatch($preview->id);
        }

        ApprovalPreview::where('repository_id', $repository->id)->whereNotIn('pr_number', $openNumbers)->delete();
        Cache::forget(ApprovalSettingsPageData::checkErrorCacheKey($repository));
        Repository::whereKey($repository->id)->update(['approval_checked_at' => now()]);
    }
}
