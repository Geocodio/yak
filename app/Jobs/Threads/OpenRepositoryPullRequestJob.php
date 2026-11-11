<?php

namespace App\Jobs\Threads;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\YakTask;
use App\Services\PullRequestBodySections;
use App\Services\Threads\PullRequests\ThreadPullRequestSync;
use App\Services\Threads\ThreadPublishGuard;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Opens the pull request for a changes repo of a thread once its CI is green.
 * Context repos never get one, and opening twice finds the first.
 */
class OpenRepositoryPullRequestJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $threadRepositoryId)
    {
        $this->onQueue('default');
    }

    public function handle(GitHubAppService $github, ThreadPullRequestSync $sync): void
    {
        $row = ThreadRepository::query()->find($this->threadRepositoryId);
        $thread = $row === null ? null : Thread::query()->withoutGlobalScopes()->find($row->thread_id);

        if ($row === null || $thread === null || ! $row->isChangesRepo() || $row->pull_request_url !== null || $row->branch === null) {
            return;
        }

        if ($this->awaitsAcknowledgement($thread)) {
            return;
        }

        $repository = Repository::query()->where('slug', $row->repo)->first();
        $fullName = Repository::githubNameFor($row->repo);
        $installationId = (int) config('yak.channels.github.installation_id');
        $summary = YakTask::query()->where('thread_id', $thread->id)->whereNotNull('result_summary')->latest('id')->value('result_summary');
        $title = $this->title($thread, $summary);

        $pull = $github->findOpenPullRequestForBranch($installationId, $fullName, $row->branch)
            ?? $github->createPullRequest($installationId, $fullName, [
                'title' => $title,
                'head' => $row->branch,
                'base' => $repository?->default_branch ?: 'main',
                'body' => mb_convert_encoding($this->body($summary), 'UTF-8', 'UTF-8'),
            ]);

        if (! isset($pull['number'], $pull['html_url'])) {
            throw new \RuntimeException('GitHub PR creation failed: ' . ($pull['message'] ?? json_encode($pull)));
        }

        $sync->recordOpened($row, (int) $pull['number'], (string) $pull['html_url'], (string) ($pull['title'] ?? $title));
        RefreshSiblingBlocksJob::dispatch($thread->id);
    }

    /**
     * Publishing a private thread needs the acknowledgement given when its
     * primary pull request was opened, so an extra repo follows that one.
     */
    private function awaitsAcknowledgement(Thread $thread): bool
    {
        if (! ThreadPublishGuard::requiresAcknowledgement($thread)) {
            return false;
        }

        return ! ThreadRepository::query()
            ->where('thread_id', $thread->id)
            ->where('role', 'primary')
            ->whereNotNull('pull_request_url')
            ->exists();
    }

    private function title(Thread $thread, ?string $summary): string
    {
        $firstLine = Str::of((string) $summary)->trim()->before("\n")->replaceMatches('/^[#>*\-\s]+/', '')->trim()->limit(100)->toString();

        return $firstLine !== '' ? $firstLine : $thread->title;
    }

    private function body(?string $summary): string
    {
        return trim((string) $summary) . "\n\n" . PullRequestBodySections::wrap(PullRequestBodySections::SIBLINGS, '');
    }
}
