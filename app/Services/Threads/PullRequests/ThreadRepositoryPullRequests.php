<?php

namespace App\Services\Threads\PullRequests;

use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\YakTask;
use App\Services\Threads\Contracts\SandboxManager;
use App\Support\LocalLinkFiles;
use App\Support\SecretFilePatterns;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Log;

/**
 * Publishes the extra changes repos of a thread after a turn: commits stray
 * work, pushes each repo that is ahead of its default branch and hands it to
 * CI. Context repos and repos without commits are never touched.
 */
class ThreadRepositoryPullRequests
{
    public function __construct(
        private readonly SandboxManager $sandboxes,
        private readonly RepositoryCiRetry $ciRetry,
    ) {}

    public function publishForTask(YakTask $task): void
    {
        $thread = $task->thread_id === null ? null : Thread::query()->withoutGlobalScopes()->find($task->thread_id);

        if ($thread !== null) {
            $this->publishChangedRepositories($thread);
        }
    }

    public function publishChangedRepositories(Thread $thread): void
    {
        $rows = ThreadRepository::query()
            ->where('thread_id', $thread->id)
            ->where('role', RepositoryRole::Changes->value)
            ->where('setup_status', RepositorySetupStatus::Ready->value)
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            if ($row->hasEnded()) {
                continue;
            }

            try {
                $this->publish($thread, $row);
            } catch (\Throwable $exception) {
                Log::channel('yak')->warning('Publishing a changes repository failed', ['thread_id' => $thread->id, 'repo' => $row->repo, 'error' => $exception->getMessage()]);
            }
        }
    }

    private function publish(Thread $thread, ThreadRepository $row): void
    {
        $repository = Repository::query()->where('slug', $row->repo)->first();
        $defaultBranch = $repository !== null && $repository->default_branch !== '' ? $repository->default_branch : 'main';

        $this->commitStrayWork($thread, $row);

        $ahead = (int) trim($this->git($thread, $row, ['rev-list', '--count', "origin/{$defaultBranch}..HEAD"])->output());

        if ($ahead === 0) {
            return;
        }

        if ($row->branch === null) {
            $row->forceFill(['branch' => $thread->branch ?? 'thread-' . $thread->id])->save();
        }

        $push = $this->git($thread, $row, ['push', 'origin', "HEAD:refs/heads/{$row->branch}"]);

        if ($push->failed()) {
            Log::channel('yak')->warning('Pushing a changes repository failed', ['thread_id' => $thread->id, 'repo' => $row->repo, 'error' => $push->errorOutput()]);

            return;
        }

        $head = $this->git($thread, $row, ['rev-parse', 'HEAD']);

        $row->forceFill([
            'ci_status' => 'running',
            'last_pushed_sha' => $head->successful() ? trim($head->output()) : null,
        ])->save();

        if ($repository?->settings()->ciSystem() === 'none') {
            $this->ciRetry->handleResult($row, true, null, null);
        }
    }

    private function commitStrayWork(Thread $thread, ThreadRepository $row): void
    {
        $names = array_values(array_filter(explode("\n", trim($this->git($thread, $row, ['ls-files', '--others', '--modified', '--exclude-standard'])->output()))));
        $addable = array_values(array_filter(
            array_diff($names, SecretFilePatterns::filter($names)),
            fn (string $name): bool => ! LocalLinkFiles::isManifest($name)
                || ! LocalLinkFiles::carriesLocalLink($this->git($thread, $row, ['diff', 'HEAD', '--', $name])->output()),
        ));

        if ($addable === []) {
            return;
        }

        $this->git($thread, $row, ['add', '--', ...$addable]);
        $this->git($thread, $row, ['commit', '-m', $thread->title]);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(Thread $thread, ThreadRepository $row, array $arguments): ProcessResult
    {
        return $this->sandboxes->exec($thread, ['git', '-C', $row->sandbox_path, ...$arguments]);
    }
}
