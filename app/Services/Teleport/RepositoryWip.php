<?php

namespace App\Services\Teleport;

use App\DataTransferObjects\WipResult;
use App\Enums\RepositoryRole;
use App\Exceptions\SandboxCommandFailed;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\Contracts\SandboxManager;
use App\Support\LocalLinkFiles;
use Illuminate\Contracts\Process\ProcessResult;

/**
 * Commits and pushes the work of one repository checkout in the sandbox for a teleport down.
 * A rejected push is a failure of that repository; there is no rebase fallback.
 */
class RepositoryWip
{
    private const SUBJECT = 'WIP: teleport to local machine';

    private const YAK_TRAILER = 'Co-authored-by: Yak <yak@geocod.io>';

    public function __construct(private readonly SandboxManager $sandboxes) {}

    /**
     * @throws SandboxCommandFailed With a message that carries no remote URL.
     */
    public function preserve(Thread $thread, ThreadRepository $repository): WipResult
    {
        if ($repository->role === RepositoryRole::Context || blank($repository->branch)) {
            return new WipResult(false, false, null);
        }

        $isDirty = trim($this->git($thread, $repository, ['status', '--porcelain'])->output()) !== '';
        $isAhead = (int) trim($this->git($thread, $repository, ['rev-list', '--count', 'HEAD', '--not', '--remotes=origin'])->output()) > 0;

        if (! $isDirty && ! $isAhead) {
            return new WipResult(false, false, null);
        }

        if ($isDirty) {
            $this->mustGit($thread, $repository, ['add', '-A'], 'add');
            $hasUnstaged = $this->unstageLocalLinks($thread, $repository);

            if (! $hasUnstaged || $this->git($thread, $repository, ['diff', '--cached', '--quiet'])->failed()) {
                $this->mustGit($thread, $repository, [...$this->identityArguments($thread), 'commit', '-m', self::SUBJECT . "\n\n" . self::YAK_TRAILER], 'commit');
            }
        }

        $this->mustGit($thread, $repository, ['push', 'origin', "HEAD:refs/heads/{$repository->branch}"], 'push');

        $sha = trim($this->mustGit($thread, $repository, ['rev-parse', 'HEAD'], 'rev-parse')->output());

        return new WipResult($isDirty, true, $sha);
    }

    /** Leaves manifests that link a sandbox path out of the commit; returns whether any were unstaged. */
    private function unstageLocalLinks(Thread $thread, ThreadRepository $repository): bool
    {
        $unstaged = false;

        foreach (array_filter(explode("\n", trim($this->git($thread, $repository, ['diff', '--cached', '--name-only'])->output()))) as $name) {
            if (LocalLinkFiles::isManifest($name) && LocalLinkFiles::carriesLocalLink($this->git($thread, $repository, ['diff', '--cached', '--', $name])->output())) {
                $this->mustGit($thread, $repository, ['reset', '-q', '--', $name], 'reset');
                $unstaged = true;
            }
        }

        return $unstaged;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(Thread $thread, ThreadRepository $repository, array $arguments): ProcessResult
    {
        return $this->sandboxes->exec($thread, ['git', '-C', $repository->sandbox_path, ...$arguments]);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function mustGit(Thread $thread, ThreadRepository $repository, array $arguments, string $operation): ProcessResult
    {
        $result = $this->git($thread, $repository, $arguments);

        if ($result->failed()) {
            throw $this->failure($thread, $repository, $operation, $result);
        }

        return $result;
    }

    private function failure(Thread $thread, ThreadRepository $repository, string $operation, ProcessResult $result): SandboxCommandFailed
    {
        $stderr = trim($result->errorOutput());
        $remote = trim($this->git($thread, $repository, ['remote', 'get-url', 'origin'])->output());

        if ($remote !== '') {
            $stderr = str_replace($remote, '[remote]', $stderr);
        }

        $stderr = mb_substr((string) preg_replace('#[a-z][a-z0-9+.-]*://\S+#i', '[url]', $stderr), 0, 500);

        return new SandboxCommandFailed("WIP {$operation} failed (exit {$result->exitCode()}): {$stderr}", (int) $result->exitCode());
    }

    /**
     * @return list<string>
     */
    private function identityArguments(Thread $thread): array
    {
        $name = $thread->git_author_name ?: config('yak.git_user_name');
        $email = $thread->git_author_email ?: config('yak.git_user_email');

        return ['-c', "user.name={$name}", '-c', "user.email={$email}"];
    }
}
