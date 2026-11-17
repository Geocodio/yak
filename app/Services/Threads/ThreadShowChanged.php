<?php

namespace App\Services\Threads;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Tells every open thread page that something its cards show changed, by
 * publishing the thread state event with a fresh {@see key()}. The event
 * goes out after the surrounding transaction commits, so a page that
 * reloads on it sees the change. A failure is reported, never thrown.
 */
class ThreadShowChanged
{
    public static function publish(?int $threadId): void
    {
        if ($threadId === null) {
            return;
        }

        DB::afterCommit(function () use ($threadId): void {
            try {
                app(ThreadEventPublisher::class)->appendThreadState($threadId);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * One short string that changes whenever the question, repository, early warning,
     * co-owner or stop cards of the thread change, the same for every viewer.
     */
    public static function key(int $threadId): string
    {
        $thread = DB::table('threads')->where('id', $threadId)->first(['repo', 'status']);
        $head = DB::table('tasks')->where('thread_id', $threadId)->orderByDesc('id')->first(['id', 'status', 'stop_kind', 'resume_after_setup_at']);
        $root = DB::table('tasks')->where('thread_id', $threadId)->orderBy('id')->first(['early_warning', 'tagged_co_owner_user_id']);
        $request = DB::table('co_owner_requests')
            ->join('tasks', 'tasks.id', '=', 'co_owner_requests.task_id')
            ->where('tasks.thread_id', $threadId)
            ->orderByDesc('co_owner_requests.id')
            ->first(['co_owner_requests.id', 'co_owner_requests.status', 'co_owner_requests.asked_user_ids']);

        $warning = $root?->early_warning === null ? null : (json_decode((string) $root->early_warning, true)['state'] ?? null);

        return substr(md5(json_encode([
            $thread?->repo, $thread?->status,
            $head?->id, $head?->status, $head?->stop_kind, $head?->resume_after_setup_at !== null,
            $warning, $root?->tagged_co_owner_user_id,
            $request?->id, $request?->status, $request?->asked_user_ids,
        ], JSON_THROW_ON_ERROR)), 0, 12);
    }
}
