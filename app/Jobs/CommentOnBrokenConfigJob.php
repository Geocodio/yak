<?php

namespace App\Jobs;

use App\Channels\GitHub\AppService;
use App\Models\Repository;
use App\Models\RepositoryConfigFile;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Comments once on the merged pull request that made a `.yak/` file invalid.
 */
class CommentOnBrokenConfigJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(
        public readonly int $repositoryId,
        public readonly string $fileName,
        public readonly string $errorCommitSha,
    ) {
        $this->onQueue('default');
    }

    public function handle(AppService $github): void
    {
        $repository = Repository::find($this->repositoryId);
        $file = RepositoryConfigFile::where('repository_id', $this->repositoryId)->where('name', $this->fileName)->first();

        if ($repository === null || $file === null) {
            return;
        }

        if ($file->error === null || $file->error_pull_request === null) {
            return;
        }

        if ($file->error_commit_sha !== $this->errorCommitSha) {
            return;
        }

        $body = view('pull-requests.broken-config-comment', [
            'fileName' => $this->fileName,
            'validSha' => $file->valid_commit_sha,
            'error' => $file->error,
            'settingsUrl' => route('repos.edit', $repository),
        ])->render();

        $key = "yak-config:broken-comment:{$this->repositoryId}:{$this->fileName}:{$this->errorCommitSha}";

        if (! Cache::add($key, true, now()->addDays(30))) {
            return;
        }

        try {
            $isPosted = $github->commentOnPullRequest(
                (int) config('yak.channels.github.installation_id'),
                $repository->github_full_name,
                $file->error_pull_request['number'],
                $body,
            );
        } catch (\Throwable $exception) {
            Cache::forget($key);

            throw $exception;
        }

        if (! $isPosted) {
            Cache::forget($key);

            throw new \RuntimeException("GitHub rejected the broken-config comment on {$repository->github_full_name}.");
        }
    }
}
