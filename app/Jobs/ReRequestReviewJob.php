<?php

namespace App\Jobs;

use App\Channels\GitHub\AppService;
use App\Models\Repository;
use App\Models\YakTask;
use App\Services\TaskLogger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * After a follow-up that came from a GitHub review succeeds, ask the
 * reviewers to look again so the PR reappears in their queue with the
 * "changes since your last review" marker.
 */
class ReRequestReviewJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    public function __construct(
        public readonly YakTask $task,
    ) {
        $this->onQueue('default');
    }

    public function handle(AppService $github): void
    {
        $logins = array_filter((array) ($this->task->re_request_review_from ?? []));
        $installationId = (int) config('yak.channels.github.installation_id');
        $prNumber = (int) ($this->task->pr_number ?? 0);

        if ($logins === [] || $installationId <= 0 || $prNumber <= 0) {
            return;
        }

        $repoSlug = Repository::githubNameFor((string) $this->task->repo);

        try {
            $github->requestReviewers($installationId, $repoSlug, $prNumber, $logins);
            TaskLogger::info($this->task, 'Re-requested review', ['reviewers' => $logins]);
        } catch (\Throwable $e) {
            Log::channel('yak')->warning('ReRequestReviewJob: GitHub rejected the review request', [
                'task_id' => $this->task->id,
                'reviewers' => $logins,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
