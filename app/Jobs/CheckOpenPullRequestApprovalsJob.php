<?php

namespace App\Jobs;

use App\Channels\GitHub\AppService;
use App\Http\Resources\ApprovalSettingsPageData;
use App\Models\ApprovalPreview;
use App\Models\Repository;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;

/**
 * Lists a repository's open pull requests and queues one approval check for
 * each one that is new, pushed to, or checked against an older concerns file.
 */
class CheckOpenPullRequestApprovalsJob implements ShouldQueue
{
    use Queueable;

    public const LISTING_ERROR = 'Could not list open pull requests on GitHub.';

    public int $timeout = 120;

    public function __construct(public readonly int $repositoryId)
    {
        $this->onQueue('default');
    }

    /**
     * @return list<WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping("approval-check:{$this->repositoryId}"))->dontRelease()->expireAfter(300)];
    }

    public function handle(AppService $github): void
    {
        $repository = Repository::find($this->repositoryId);
        if ($repository === null) {
            return;
        }

        try {
            $pullRequests = $github->listOpenPullRequests((int) config('yak.channels.github.installation_id'), $repository->github_full_name, orFail: true);
        } catch (\Throwable $exception) {
            report($exception);
            ApprovalPreview::where('repository_id', $repository->id)->update(['status' => 'failed', 'error' => self::LISTING_ERROR]);
            Cache::forever(ApprovalSettingsPageData::checkErrorCacheKey($repository), self::LISTING_ERROR);

            return;
        }
        $concernsVersion = $repository->settings()->concernsVersion();
        $openNumbers = [];

        foreach ($pullRequests as $pullRequest) {
            $number = (int) $pullRequest['number'];
            $headSha = (string) ($pullRequest['head']['sha'] ?? '');
            $openNumbers[] = $number;

            $preview = ApprovalPreview::firstOrNew(['repository_id' => $repository->id, 'pr_number' => $number]);
            $preview->fill([
                'title' => (string) ($pullRequest['title'] ?? ''),
                'author' => (string) ($pullRequest['user']['login'] ?? ''),
                'url' => (string) ($pullRequest['html_url'] ?? ''),
            ]);

            $isCurrent = $preview->exists && $preview->status === 'done'
                && $preview->head_sha === $headSha && $preview->concerns_version === $concernsVersion;
            if ($isCurrent) {
                $preview->save();

                continue;
            }

            $preview->fill(['head_sha' => $headSha, 'status' => 'pending', 'concerns_version' => $concernsVersion, 'result' => null, 'error' => null])->save();
            CheckPullRequestApprovalJob::dispatch($preview->id);
        }

        ApprovalPreview::where('repository_id', $repository->id)->whereNotIn('pr_number', $openNumbers)->delete();
        Cache::forget(ApprovalSettingsPageData::checkErrorCacheKey($repository));
        Cache::forever(ApprovalSettingsPageData::checkedAtCacheKey($repository), now()->toIso8601String());
    }
}
