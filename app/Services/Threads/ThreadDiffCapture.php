<?php

namespace App\Services\Threads;

use App\Enums\DiffFileStatus;
use App\Events\ThreadDiffUpdated;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Services\Threads\Contracts\SandboxManager;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Stores the sandbox's diff against the merge base with the default branch, including
 * untracked files, as the thread's latest diff. Git runs against a temporary index so the
 * agent's own index is never touched.
 */
class ThreadDiffCapture
{
    public const MAX_FILES = 500;

    public function __construct(private readonly SandboxManager $sandboxes) {}

    /**
     * The largest patch stored for one file.
     */
    public static function maxFileBytes(): int
    {
        return intdiv((int) config('yak.threads.diff_max_bytes'), 4);
    }

    /**
     * Replaces the thread's previous diff. A failure is reported and leaves the previous diff in place.
     */
    public function capture(Thread $thread): ?ThreadDiff
    {
        $index = '/tmp/yak-diff-index-' . Str::random(12);

        try {
            $diff = $this->captureWithIndex($thread, $index);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        } finally {
            try {
                $this->sandboxes->exec($thread, ['rm', '-f', $index]);
            } catch (Throwable) {
                // The temporary index lives in /tmp and goes away with the sandbox.
            }
        }

        ThreadDiffUpdated::dispatch($thread, $diff);

        return $diff;
    }

    private function captureWithIndex(Thread $thread, string $index): ThreadDiff
    {
        $defaultBranch = Repository::query()->where('slug', $thread->repo)->value('default_branch') ?: 'main';
        $env = ['GIT_INDEX_FILE' => $index];

        $headSha = trim($this->git($thread, ['rev-parse', 'HEAD'])->output());
        $mergeBase = $this->sandboxes->exec($thread, ['git', 'merge-base', 'HEAD', "origin/{$defaultBranch}"]);
        $baseSha = $mergeBase->successful() && trim($mergeBase->output()) !== '' ? trim($mergeBase->output()) : $headSha;

        $this->git($thread, ['read-tree', 'HEAD'], $env);
        $this->git($thread, ['add', '-A'], $env);

        $statuses = $this->parseNameStatus($this->git($thread, ['diff', '--cached', '-M', '--name-status', '-z', $baseSha], $env)->output());
        $counts = $this->parseNumstat($this->git($thread, ['diff', '--cached', '-M', '--numstat', '-z', $baseSha], $env)->output());

        $maxFile = self::maxFileBytes();
        $maxTotal = (int) config('yak.threads.diff_max_bytes');
        $stored = 0;
        $isTruncated = false;
        $rows = [];

        foreach ($statuses as $position => $file) {
            [$additions, $deletions] = $counts[$file['path']] ?? [null, null];
            $isBinary = $additions === null;
            $patch = null;
            $isTooLarge = false;

            if (! $isBinary && ($position >= self::MAX_FILES || $stored >= $maxTotal)) {
                // Past either cap no patch is stored, so the per-file git command is skipped too.
                $isTooLarge = true;
                $isTruncated = true;
            } elseif (! $isBinary) {
                $paths = array_values(array_filter([$file['oldPath'], $file['path']], fn (?string $path): bool => $path !== null));
                $patch = $this->git($thread, ['diff', '--cached', '-M', $baseSha, '--', ...$paths], $env)->output();

                if (strlen($patch) > $maxFile) {
                    $isTooLarge = true;
                } elseif ($stored + strlen($patch) > $maxTotal) {
                    $isTooLarge = true;
                    $isTruncated = true;
                }

                if ($isTooLarge) {
                    $patch = null;
                } else {
                    $stored += strlen($patch);
                    $patch = mb_scrub($patch, 'UTF-8');
                }
            }

            $rows[] = [
                'path' => mb_scrub($file['path'], 'UTF-8'),
                'old_path' => $file['oldPath'] === null ? null : mb_scrub($file['oldPath'], 'UTF-8'),
                'status' => $file['status']->value,
                'additions' => $additions,
                'deletions' => $deletions,
                'is_binary' => $isBinary,
                'is_too_large' => $isTooLarge,
                'patch' => $patch,
            ];
        }

        return DB::transaction(function () use ($thread, $baseSha, $headSha, $rows, $isTruncated): ThreadDiff {
            ThreadDiff::query()->where('thread_id', $thread->id)->delete();

            $diff = ThreadDiff::query()->create([
                'thread_id' => $thread->id,
                'task_run_id' => $thread->runs()->max('id'),
                'base_sha' => $baseSha,
                'head_sha' => $headSha,
                'files_count' => count($rows),
                'additions' => array_sum(array_column($rows, 'additions')),
                'deletions' => array_sum(array_column($rows, 'deletions')),
                'is_truncated' => $isTruncated,
                'captured_at' => now(),
            ]);

            $diff->files()->createMany($rows);

            return $diff;
        });
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $env
     */
    private function git(Thread $thread, array $arguments, array $env = []): ProcessResult
    {
        $result = $this->sandboxes->exec($thread, ['git', ...$arguments], null, $env);

        if ($result->failed()) {
            // Remotes can embed credentials, so no URL survives into the message.
            $stderr = mb_substr((string) preg_replace('#[a-z][a-z0-9+.-]*://\S+#i', '[url]', trim($result->errorOutput())), 0, 500);

            throw new RuntimeException("Diff command failed for thread {$thread->id} (exit {$result->exitCode()}): {$stderr}");
        }

        return $result;
    }

    /**
     * @return list<array{path: string, oldPath: string|null, status: DiffFileStatus}>
     */
    private function parseNameStatus(string $output): array
    {
        $tokens = explode("\0", rtrim($output, "\0\n"));
        $files = [];

        for ($i = 0; $i < count($tokens) && $tokens[$i] !== ''; $i++) {
            $code = $tokens[$i][0];

            if ($code === 'R' || $code === 'C') {
                $files[] = ['path' => $tokens[$i + 2], 'oldPath' => $tokens[$i + 1], 'status' => DiffFileStatus::Renamed];
                $i += 2;

                continue;
            }

            $files[] = [
                'path' => $tokens[++$i],
                'oldPath' => null,
                'status' => match ($code) {
                    'A' => DiffFileStatus::Added,
                    'D' => DiffFileStatus::Deleted,
                    default => DiffFileStatus::Modified,
                },
            ];
        }

        return $files;
    }

    /**
     * @return array<string, array{0: int|null, 1: int|null}> keyed by the new path; null counts mean binary
     */
    private function parseNumstat(string $output): array
    {
        $tokens = explode("\0", rtrim($output, "\0\n"));
        $counts = [];

        for ($i = 0; $i < count($tokens) && $tokens[$i] !== ''; $i++) {
            [$additions, $deletions, $path] = explode("\t", $tokens[$i], 3) + [2 => ''];

            // A rename lists an empty path, then the old and new paths as separate tokens.
            if ($path === '') {
                $path = $tokens[$i + 2] ?? '';
                $i += 2;
            }

            $counts[$path] = $additions === '-' ? [null, null] : [(int) $additions, (int) $deletions];
        }

        return $counts;
    }
}
