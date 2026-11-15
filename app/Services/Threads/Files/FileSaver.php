<?php

namespace App\Services\Threads\Files;

use App\DataTransferObjects\Files\FileContent;
use App\DataTransferObjects\Files\SaveOutcome;
use App\Enums\FileScope;
use App\Exceptions\SandboxCommandFailed;
use App\Exceptions\ThreadActionRefused;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\ThreadRecovery;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Writes a person's edit into the sandbox, but only over the file they opened:
 * when the bytes on disk no longer hash to the sha256 the editor started from,
 * nothing is written and the current file is returned as a conflict.
 */
class FileSaver
{
    public const WRITE_TIMEOUT_SECONDS = 30;

    private const EXIT_OUTSIDE_ROOT = 3;

    public function __construct(private readonly SandboxFiles $files) {}

    public function save(Thread $thread, User $user, FileScope $scope, string $path, string $content, string $baseSha256, bool $overwrite): SaveOutcome
    {
        if (! $thread->isOpen()) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, ThreadRecovery::CLOSED_SENTENCE);
        }

        $absolute = SandboxPath::resolve($thread, $scope, $path);

        if (! SandboxPath::isWritable($absolute)) {
            throw new InvalidFilePath('This folder is read-only.');
        }

        if (strlen($content) > (int) config('yak.files.max_edit_bytes')) {
            throw new InvalidFilePath('The file is too large to edit.');
        }

        $current = $this->current($thread, $scope, $path);

        if ($current?->isTooLarge || $current?->isBinary) {
            throw new InvalidFilePath('The file cannot be edited as text.');
        }

        $matches = $current === null ? $baseSha256 === '' : $current->sha256 === $baseSha256;

        if (! $matches && ! $overwrite) {
            return new SaveOutcome(false, $current, null);
        }

        if ($overwrite && $current?->sha256 === hash('sha256', $content)) {
            return new SaveOutcome(true, null, $current, unchanged: true);
        }

        $this->write($thread, $scope, $absolute, $content);

        return new SaveOutcome(true, null, $this->files->read($thread, $scope, $path));
    }

    private function current(Thread $thread, FileScope $scope, string $path): ?FileContent
    {
        try {
            return $this->files->read($thread, $scope, $path);
        } catch (NotFoundHttpException) {
            return null;
        }
    }

    private function write(Thread $thread, FileScope $scope, string $absolute, string $content): void
    {
        $target = escapeshellarg($absolute);
        $allowed = $scope === FileScope::Repository
            ? [SandboxPath::root($thread, $scope)]
            : ['/home/yak', rtrim((string) config('yak.sandbox.workspace_path'), '/')];
        $patterns = implode('|', array_map(fn (string $root): string => escapeshellarg($root . '/') . '*', $allowed));

        $script = sprintf(
            'set -e; target=%1$s; dir=$(dirname -- "$target"); case "$(realpath -m -- "$target")" in %2$s) ;; *) exit %3$d;; esac; '
            . 'mkdir -p -- "$dir"; tmp=$(mktemp -- "$dir/.yak-save.XXXXXX"); trap \'rm -f -- "$tmp"\' EXIT; '
            . 'base64 -d > "$tmp"; if [ -e "$target" ]; then chmod --reference="$target" -- "$tmp"; else chmod 644 -- "$tmp"; fi; mv -- "$tmp" "$target"',
            $target,
            $patterns,
            self::EXIT_OUTSIDE_ROOT,
        );

        try {
            $result = app(SandboxManager::class)->exec($thread, ['bash', '-lc', $script], base64_encode($content), [], self::WRITE_TIMEOUT_SECONDS);
        } catch (ProcessTimedOutException) {
            throw new SandboxCommandFailed('A sandbox file command timed out.');
        }

        if ($result->exitCode() === self::EXIT_OUTSIDE_ROOT) {
            throw new InvalidFilePath('The path is outside the allowed folder.');
        }

        if ($result->failed()) {
            throw new SandboxCommandFailed('Writing a sandbox file failed.');
        }
    }
}
