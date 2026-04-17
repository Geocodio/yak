<?php

namespace App\Actions;

use App\Models\Repository;
use App\Services\GitHubAppService;

class ApplyPrReviewToOpenPulls
{
    public function __invoke(Repository $repository): int
    {
        $installationId = (int) config('yak.channels.github.installation_id');
        $github = app(GitHubAppService::class);
        $yakBot = $github->appBotLogin();

        $prs = $github->listOpenPullRequests($installationId, $repository->slug);

        $enqueued = 0;

        foreach ($prs as $pr) {
            if ((bool) ($pr['draft'] ?? false)) {
                continue;
            }
            if ((string) ($pr['user']['login'] ?? '') === $yakBot) {
                continue;
            }

            $task = app(EnqueuePrReview::class)->dispatch($repository, $pr, 'full');

            if ($task !== null) {
                $enqueued++;
            }
        }

        return $enqueued;
    }
}
