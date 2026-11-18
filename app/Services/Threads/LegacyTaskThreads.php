<?php

namespace App\Services\Threads;

use App\Enums\RepositoryRole;
use App\Enums\ThreadStatus;
use App\Enums\ThreadVisibility;
use App\Models\Thread;
use App\Models\YakTask;
use App\Support\AttachmentReferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gives a work task from before threads a closed thread that shows its stored
 * history, so the task's old links keep a page. The sandbox is gone, so the
 * thread has none.
 */
class LegacyTaskThreads
{
    public const string CLOSE_REASON = 'legacy';

    public function __construct(private readonly ThreadOrigin $origin) {}

    /**
     * The root's thread, created when it has none. Running it again returns
     * the thread it made. A conversation that already has a thread on any of
     * its tasks is left alone and gives null.
     */
    public function convert(YakTask $root): ?Thread
    {
        return DB::transaction(function () use ($root): ?Thread {
            $locked = YakTask::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($root->id);

            if ($locked->thread_id !== null) {
                return Thread::query()->withoutGlobalScopes()->findOrFail($locked->thread_id);
            }

            $conversation = $locked->conversation();

            if ($conversation->contains(fn (YakTask $task): bool => $task->thread_id !== null)) {
                return null;
            }

            $lastActivityAt = $conversation->max('updated_at');
            $title = Str::limit(AttachmentReferences::stripFromText($locked->headline()) ?: $locked->repo, 120, '');
            $pullRequestTask = $conversation->last(fn (YakTask $task): bool => $task->pr_url !== null);

            $thread = Thread::query()->create([
                'name' => Str::limit(Str::slug($title), 40, '') . '-' . Str::lower(Str::random(6)),
                'title' => $title,
                'repo' => $locked->repo,
                'status' => ThreadStatus::Closed,
                'visibility' => ThreadVisibility::Team,
                'created_by_user_id' => $locked->started_by_user_id,
                'owner_user_id' => $locked->started_by_user_id,
                'branch' => $locked->branch_name,
                'is_legacy' => true,
                'legacy_root_task_id' => $locked->id,
                'close_reason' => self::CLOSE_REASON,
                'closed_at' => $lastActivityAt,
                'last_activity_at' => $lastActivityAt,
                'created_at' => $locked->created_at,
                'updated_at' => $lastActivityAt,
                ...$this->origin->forRequest($locked),
            ]);

            if ($thread->hasRepository()) {
                $thread->repositories()->create([
                    'repo' => $thread->repo,
                    'role' => RepositoryRole::Primary,
                    'sandbox_path' => (string) config('yak.sandbox.workspace_path', '/workspace'),
                    'branch' => $locked->branch_name,
                    'pull_request_url' => $pullRequestTask?->pr_url,
                    'pull_request_number' => $pullRequestTask?->pullRequestNumber(),
                ]);
            }

            YakTask::query()->withoutGlobalScopes()->whereIn('id', $conversation->pluck('id'))->whereNull('thread_id')
                ->toBase()->update(['thread_id' => $thread->id]);

            return $thread;
        });
    }
}
