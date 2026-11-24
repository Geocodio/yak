<?php

namespace App\Jobs;

use App\Channels\GitHub\AppService;
use App\Services\Threads\PullRequests\PullRequestFacts;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Copies GitHub's mergeable_state for one pull request onto its thread
 * repository rows. GitHub computes it lazily, so a null answer is retried.
 */
class RefreshPullRequestMergeabilityJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $repoFullName, public int $prNumber)
    {
        $this->onQueue('default');
    }

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [15, 30, 60, 120];
    }

    public function handle(AppService $github, PullRequestFacts $facts): void
    {
        $pullRequest = $github->getPullRequest((int) config('yak.channels.github.installation_id'), $this->repoFullName, $this->prNumber);

        if (($pullRequest['mergeable'] ?? null) === null) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff()[$this->attempts() - 1]);
            }

            return;
        }

        foreach ($facts->rowsFor($this->repoFullName, $this->prNumber) as $row) {
            $row->pull_request_mergeable_state = (string) ($pullRequest['mergeable_state'] ?? 'unknown');
            $row->save();
        }
    }
}
