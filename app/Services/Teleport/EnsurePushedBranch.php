<?php

namespace App\Services\Teleport;

use App\Exceptions\SandboxCommandFailed;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Repos\ThreadGitHubCredentials;

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
