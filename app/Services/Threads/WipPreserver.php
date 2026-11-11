<?php

namespace App\Services\Threads;

use App\DataTransferObjects\WipResult;
use App\Enums\RepositoryRole;
use App\Exceptions\SandboxCommandFailed;
use App\Models\Thread;
use App\Services\Teleport\RepositoryWip;
use App\Services\Threads\Contracts\SandboxManager;
use Illuminate\Contracts\Process\ProcessResult;
use Throwable;

/**
 * Commits and pushes whatever is in the sandbox workspace to the thread
 * branch, so destroying or evicting the sandbox never loses work.
 */
class WipPreserver
{
    private const SUBJECT = 'WIP: Yak sandbox snapshot';

    private const YAK_TRAILER = 'Co-authored-by: Yak <yak@geocod.io>';

    public function __construct(private SandboxManager $sandboxes, private RepositoryWip $repositoryWip) {}

    private const TELEPORT_SUBJECT = 'WIP: teleport to local machine';

    public function preserve(Thread $thread): WipResult
    {
        return $this->run($thread, $this->message($thread));
    }

    /**
     * The same commit and push for a teleport down, always signed with the Yak trailer.
     */
    /**
     * Preserves the primary workspace and every Changes extra. Every repository is attempted;
     * the first failure is rethrown afterwards so no push hides another.
     */
    public function preserveAll(Thread $thread): void
    {
        $failure = null;

        try {
            $this->preserve($thread);
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        $rows = $thread->repositories()->where('role', RepositoryRole::Changes)->whereNotNull('branch')->get();

        foreach ($rows as $row) {
            try {
                $this->repositoryWip->preserve($thread, $row);
            } catch (Throwable $exception) {
                $failure ??= $exception;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }

    public function preserveForTeleport(Thread $thread): WipResult
    {
        return $this->run($thread, self::TELEPORT_SUBJECT . "\n\n" . self::YAK_TRAILER);
    }

    private function run(Thread $thread, string $message): WipResult
    {
        // A thread without a branch has nowhere to push; nothing is preserved.
        if (blank($thread->branch)) {
            return new WipResult(false, false, null);
        }

        $isDirty = trim($this->git($thread, ['status', '--porcelain'])->output()) !== '';
        $isAhead = (int) trim($this->git($thread, ['rev-list', '--count', 'HEAD', '--not', '--remotes=origin'])->output()) > 0;

        if (! $isDirty && ! $isAhead) {
            return new WipResult(false, false, null);
        }

        if ($isDirty) {
            $this->mustGit($thread, ['add', '-A'], 'add');
            $this->mustGit($thread, [...$this->identityArguments($thread), 'commit', '-m', $message], 'commit');
        }

        $this->push($thread);

        $sha = trim($this->mustGit($thread, ['rev-parse', 'HEAD'], 'rev-parse')->output());
        $thread->forceFill(['wip_commit_sha' => $sha])->save();

        return new WipResult($isDirty, true, $sha);
    }

    private function push(Thread $thread): void
    {
        $branch = (string) $thread->branch;
        $target = "HEAD:refs/heads/{$branch}";

        $first = $this->git($thread, ['push', 'origin', $target]);

        if ($first->successful()) {
            return;
        }

        // No remote branch to rebase onto means the rejection is not a divergence.
        if ($this->git($thread, ['fetch', 'origin', $branch])->failed()) {
            throw $this->failure($thread, 'push', $first);
        }

        $rebase = $this->git($thread, [...$this->identityArguments($thread), 'rebase', 'FETCH_HEAD']);

        if ($rebase->failed()) {
            $this->git($thread, ['rebase', '--abort']);

            throw $this->failure($thread, 'rebase', $rebase);
        }

        $this->mustGit($thread, ['push', 'origin', $target], 'push');
    }

    /**
     * @param  list<string>  $arguments
     */
    private function git(Thread $thread, array $arguments): ProcessResult
    {
        return $this->sandboxes->exec($thread, ['git', ...$arguments]);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function mustGit(Thread $thread, array $arguments, string $operation): ProcessResult
    {
        $result = $this->git($thread, $arguments);

        if ($result->failed()) {
            throw $this->failure($thread, $operation, $result);
        }

        return $result;
    }

    /** Error text never carries a URL, since remotes can embed credentials. */
    private function failure(Thread $thread, string $operation, ProcessResult $result): SandboxCommandFailed
    {
        $stderr = trim($result->errorOutput());
        $remote = trim($this->git($thread, ['remote', 'get-url', 'origin'])->output());

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

    private function message(Thread $thread): string
    {
        return filled($thread->git_author_name)
            ? self::SUBJECT . "\n\n" . self::YAK_TRAILER
            : self::SUBJECT;
    }
}
