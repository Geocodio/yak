<?php

namespace App\Services\Threads;

use App\Enums\DiffFileStatus;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Services\IncusSandboxManager;
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
    public function __construct(private readonly IncusSandboxManager $sandboxes) {}

    /**
     * Replaces the thread's previous diff. A failure is reported and leaves the previous diff in place.
     */
    public function capture(Thread $thread, string $container): ?ThreadDiff
    {
        $index = '/tmp/yak-diff-index-' . Str::random(12);

        try {
            return $this->captureWithIndex($thread, $container, $index);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        } finally {
            try {
                $this->sandboxes->run($container, 'rm -f ' . escapeshellarg($index), timeout: 10);
            } catch (Throwable) {
                // The temporary index lives in /tmp and goes away with the sandbox.
            }
        }
    }

    private function captureWithIndex(Thread $thread, string $container, string $index): ThreadDiff
    {
        $defaultBranch = Repository::query()->where('slug', $thread->repo)->value('default_branch') ?: 'main';
        $git = 'cd ' . escapeshellarg(IncusSandboxManager::workspacePath()) . ' && GIT_INDEX_FILE=' . escapeshellarg($index) . ' git';

        $shas = preg_split('/\s+/', trim($this->output(
            $container,
            'cd ' . escapeshellarg(IncusSandboxManager::workspacePath())
                . ' && git merge-base HEAD ' . escapeshellarg('origin/' . $defaultBranch)
                . ' && git rev-parse HEAD'
                . " && {$git} read-tree HEAD && {$git} add -A",
        ))) ?: [];

        if (count($shas) < 2 || $shas[0] === '') {
            throw new RuntimeException("Thread {$thread->id} has no merge base to diff against.");
        }

        [$baseSha, $headSha] = $shas;

        $statuses = $this->parseNameStatus($this->output($container, "{$git} diff --cached -M --name-status -z {$baseSha}"));
        $counts = $this->parseNumstat($this->output($container, "{$git} diff --cached -M --numstat -z {$baseSha}"));

        $maxFile = (int) config('yak.threads.diff_max_file_bytes');
        $maxTotal = (int) config('yak.threads.diff_max_total_bytes');
        $stored = 0;
        $isTruncated = false;
        $rows = [];

        foreach ($statuses as $file) {
            [$additions, $deletions] = $counts[$file['path']] ?? [null, null];
            $isBinary = $additions === null;
            $patch = null;
            $isTooLarge = false;

            if (! $isBinary) {
                $paths = collect([$file['oldPath'], $file['path']])->filter()->map(escapeshellarg(...))->implode(' ');
                $patch = $this->output($container, "{$git} diff --cached -M {$baseSha} -- {$paths} | head -c " . ($maxFile + 1));

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
                'path' => $file['path'],
                'old_path' => $file['oldPath'],
                'status' => $file['status']->value,
                'additions' => $additions,
                'deletions' => $deletions,
                'is_binary' => $isBinary,
                'is_too_large' => $isTooLarge,
                'patch' => $patch,
            ];
        }

        $diff = DB::transaction(function () use ($thread, $baseSha, $headSha, $rows, $isTruncated): ThreadDiff {
            ThreadDiff::query()->where('thread_id', $thread->id)->delete();

            $diff = ThreadDiff::query()->create([
                'thread_id' => $thread->id,
                'task_run_id' => $thread->turns()->max('id'),
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

        app(ThreadEventPublisher::class)->append($thread->id, 'diff.updated', [
            'filesCount' => $diff->files_count,
            'additions' => $diff->additions,
            'deletions' => $diff->deletions,
            'capturedAt' => $diff->captured_at->toIso8601String(),
        ]);

        return $diff;
    }

    private function output(string $container, string $command): string
    {
        $result = $this->sandboxes->run($container, $command, timeout: 60);

        if ($result->failed()) {
            throw new RuntimeException('Diff command failed: ' . $result->errorOutput());
        }

        return $result->output();
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
