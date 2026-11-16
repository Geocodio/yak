<?php

namespace App\Services\Threads\Repos;

use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use App\Models\Thread;
use App\Models\ThreadDiff;
use App\Models\ThreadRepository;
use App\Services\Threads\ThreadDiffCapture;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Stores the diff of each extra repository next to the primary's, using the same
 * temporary index technique. A context repository's diff is kept for display
 * only and flagged throwaway. One repository failing never affects the others
 * or the primary's diff.
 */
class RepositoryDiffCapture
{
    public function __construct(private readonly ThreadDiffCapture $capture) {}

    public function capture(Thread $thread, ThreadDiff $diff, string $index): void
    {
        $extras = $thread->repositories()
            ->where('role', '!=', RepositoryRole::Primary->value)
            ->where('setup_status', RepositorySetupStatus::Ready->value)
            ->orderBy('id')
            ->get();

        foreach ($extras as $repository) {
            try {
                $this->captureOne($thread, $diff, $repository, $index);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function captureOne(Thread $thread, ThreadDiff $diff, ThreadRepository $repository, string $index): void
    {
        $snapshot = $this->capture->inspect($thread, $index, $repository->repo, $repository->sandbox_path);
        $rows = $snapshot['rows'];

        DB::transaction(function () use ($diff, $repository, $snapshot, $rows): void {
            $repositoryDiff = $diff->repositories()->create([
                'repo' => $repository->repo,
                'role' => $repository->role,
                'base_sha' => $snapshot['baseSha'],
                'head_sha' => $snapshot['headSha'],
                'files_count' => count($rows),
                'additions' => array_sum(array_column($rows, 'additions')),
                'deletions' => array_sum(array_column($rows, 'deletions')),
                'is_throwaway' => ! $repository->getsPullRequest(),
            ]);

            // Files share thread_diff_files so the file patch route serves them too.
            $diff->files()->createMany(array_map(fn (array $row): array => [...$row, 'thread_diff_repository_id' => $repositoryDiff->id], $rows));
        });
    }
}
