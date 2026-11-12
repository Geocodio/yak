<?php

namespace App\Http\Resources;

use App\Enums\DiffFileStatus;
use App\Enums\RepositoryRole;
use App\Models\Thread;
use App\Services\Threads\ThreadTurns;

/**
 * Body of `threads.diff.show`. The array shape below is the source of truth for
 * `resources/js/types/thread-changes.ts`.
 */
final class ThreadDiffData
{
    /**
     * @return array{diff: null|array{
     *     baseSha: string,
     *     headSha: string,
     *     capturedAt: string,
     *     isTruncated: bool,
     *     filesCount: int,
     *     additions: int,
     *     deletions: int,
     *     throwawayFilesCount: int,
     *     isTurnRunning: bool,
     *     repositories: list<array{repo: string, role: value-of<RepositoryRole>, isThrowaway: bool, baseSha: string, headSha: string, filesCount: int, additions: int, deletions: int, branch: ?string, pullRequestUrl: ?string}>,
     *     files: list<array{id: int, repo: string, path: string, oldPath: ?string, status: value-of<DiffFileStatus>, additions: ?int, deletions: ?int, isBinary: bool, isTooLarge: bool}>,
     * }}
     */
    public static function build(Thread $thread): array
    {
        $diff = $thread->diff()->with(['files', 'repositories'])->first();

        if ($diff === null) {
            return ['diff' => null];
        }

        $checkouts = $thread->repositories()->get()->keyBy('repo');
        $repositories = [[
            'repo' => (string) $thread->repo,
            'role' => RepositoryRole::Primary->value,
            'isThrowaway' => false,
            'baseSha' => $diff->base_sha,
            'headSha' => $diff->head_sha,
            'filesCount' => $diff->files->whereNull('thread_diff_repository_id')->count(),
            'additions' => $diff->additions,
            'deletions' => $diff->deletions,
            'branch' => $checkouts->get((string) $thread->repo)?->branch,
            'pullRequestUrl' => $checkouts->get((string) $thread->repo)?->pull_request_url,
        ]];
        $filesCount = $diff->files_count;
        $additions = $diff->additions;
        $deletions = $diff->deletions;
        $throwawayFilesCount = 0;
        $repoById = [];

        foreach ($diff->repositories as $extra) {
            $repoById[$extra->id] = $extra->repo;
            $repositories[] = [
                'repo' => $extra->repo,
                'role' => $extra->role->value,
                'isThrowaway' => $extra->is_throwaway,
                'baseSha' => $extra->base_sha,
                'headSha' => $extra->head_sha,
                'filesCount' => $extra->files_count,
                'additions' => $extra->additions,
                'deletions' => $extra->deletions,
                'branch' => $checkouts->get($extra->repo)?->branch,
                'pullRequestUrl' => $checkouts->get($extra->repo)?->pull_request_url,
            ];

            // Header totals count only repositories that get a pull request.
            if ($extra->is_throwaway) {
                $throwawayFilesCount += $extra->files_count;
            } else {
                $filesCount += $extra->files_count;
                $additions += $extra->additions;
                $deletions += $extra->deletions;
            }
        }

        $files = [];

        foreach ($diff->files as $file) {
            $files[] = [
                'id' => $file->id,
                'repo' => ($file->thread_diff_repository_id === null ? null : $repoById[$file->thread_diff_repository_id] ?? null) ?? (string) $thread->repo,
                'path' => $file->path,
                'oldPath' => $file->old_path,
                'status' => $file->status->value,
                'additions' => $file->additions,
                'deletions' => $file->deletions,
                'isBinary' => $file->is_binary,
                'isTooLarge' => $file->is_too_large,
            ];
        }

        return ['diff' => [
            'baseSha' => $diff->base_sha,
            'headSha' => $diff->head_sha,
            'capturedAt' => $diff->captured_at->toIso8601String(),
            'isTruncated' => $diff->is_truncated,
            'filesCount' => $filesCount,
            'additions' => $additions,
            'deletions' => $deletions,
            'throwawayFilesCount' => $throwawayFilesCount,
            'isTurnRunning' => ThreadTurns::runningTurn($thread) !== null,
            'repositories' => $repositories,
            'files' => $files,
        ]];
    }
}
