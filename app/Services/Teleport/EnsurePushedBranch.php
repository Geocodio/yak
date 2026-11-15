<?php

namespace App\Services\Teleport;

use App\Enums\RepositoryRole;
use App\Exceptions\SandboxCommandFailed;
use App\Models\Repository;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Repos\ThreadGitHubCredentials;
use Illuminate\Support\Facades\Log;

/**
 * Guarantees a branch exists on the remote before a teleport down records its head.
 * A fresh Yak branch sits at the default branch head, so nothing else would push it.
 */
class EnsurePushedBranch
{
    public function __construct(private readonly SandboxManager $sandboxes) {}

    /**
     * @throws SandboxCommandFailed With a message that carries no remote URL.
     */
    public function ensure(Thread $thread, string $path, string $branch): void
    {
        app(ThreadGitHubCredentials::class)->ensureFresh($thread);

        if ($this->existsOnRemote($thread, $path, $branch)) {
            return;
        }

        $this->push($thread, $path, $branch);
    }

    /**
     * pushIfBehind() for the thread's primary repository and its branch.
     *
     * @throws SandboxCommandFailed With a message that carries no remote URL.
     */
    public function pushPrimaryIfBehind(Thread $thread): bool
    {
        $primary = $thread->repositories()->where('role', RepositoryRole::Primary)->first();
        $branch = blank($primary?->branch) ? $thread->branch : $primary->branch;

        if ($primary === null || blank($branch)) {
            return false;
        }

        return $this->pushIfBehind($thread, $primary->sandbox_path, (string) $branch);
    }

    /**
     * Pushes the checked-out branch when its commits are not on the remote yet: a branch the remote lacks, or one
     * behind HEAD. Only a fast-forward goes out, so work a person pushed to the branch is never overwritten.
     *
     * @return bool Whether a push happened.
     *
     * @throws SandboxCommandFailed With a message that carries no remote URL.
     */
    public function pushIfBehind(Thread $thread, string $path, string $branch): bool
    {
        $current = trim($this->sandboxes->exec($thread, ['git', '-C', $path, 'rev-parse', '--abbrev-ref', 'HEAD'])->output());
        $head = trim($this->sandboxes->exec($thread, ['git', '-C', $path, 'rev-parse', 'HEAD'])->output());

        if ($current !== $branch || $head === '') {
            return false;
        }

        $repository = Repository::query()->where('slug', $thread->repo)->first();
        $defaultBranches = filled($repository?->default_branch) ? [$repository->default_branch] : ['main', 'master'];

        if (in_array($branch, $defaultBranches, true)) {
            Log::channel('yak')->warning('Refused to push the default branch of a thread', ['thread_id' => $thread->id, 'branch' => $branch]);

            return false;
        }

        app(ThreadGitHubCredentials::class)->ensureFresh($thread);

        $remote = $this->sandboxes->exec($thread, ['git', '-C', $path, 'ls-remote', '--exit-code', '--heads', 'origin', $branch]);

        if ($remote->exitCode() !== 0 && $remote->exitCode() !== 2) {
            return false;
        }

        $remoteHead = $remote->exitCode() === 0 ? (string) strtok(trim($remote->output()), "\t ") : '';

        if ($remoteHead === $head) {
            return false;
        }

        $isFastForward = $remoteHead === '' || $this->sandboxes->exec($thread, ['git', '-C', $path, 'merge-base', '--is-ancestor', $remoteHead, 'HEAD'])->successful();

        if (! $isFastForward) {
            return false;
        }

        $this->push($thread, $path, $branch);

        return true;
    }

    /**
     * @throws SandboxCommandFailed With a message that carries no remote URL.
     */
    private function push(Thread $thread, string $path, string $branch): void
    {
        $push = $this->sandboxes->exec($thread, ['git', '-C', $path, 'push', 'origin', "HEAD:refs/heads/{$branch}"]);

        if ($push->failed()) {
            $stderr = trim($push->errorOutput());
            $remote = trim($this->sandboxes->exec($thread, ['git', '-C', $path, 'remote', 'get-url', 'origin'])->output());

            if ($remote !== '') {
                $stderr = str_replace($remote, '[remote]', $stderr);
            }

            $stderr = mb_substr((string) preg_replace('#[a-z][a-z0-9+.-]*://\S+#i', '[url]', $stderr), 0, 500);

            throw new SandboxCommandFailed("WIP push failed (exit {$push->exitCode()}): {$stderr}", (int) $push->exitCode());
        }
    }

    /**
     * Exit 2 means the remote answered and the branch is absent; any other failure is not taken as absent.
     */
    public function existsOnRemote(Thread $thread, string $path, string $branch): bool
    {
        return $this->sandboxes->exec($thread, ['git', '-C', $path, 'ls-remote', '--exit-code', '--heads', 'origin', $branch])->exitCode() !== 2;
    }
}
