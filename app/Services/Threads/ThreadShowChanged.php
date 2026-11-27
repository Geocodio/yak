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
                app(ThreadEventPublisher::class)->appendThreadState($threadId, withShowKey: true);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * One short string that changes whenever the question, repository, early warning,
     * co-owner, stop or CI checks cards, the slash commands or the list of queued messages of the thread change, the same for every viewer.
     */
    public static function key(int $threadId): string
    {
        $thread = DB::table('threads')->where('id', $threadId)->first(['repo', 'status', 'slash_commands_captured_at']);
        $head = DB::table('tasks')->where('thread_id', $threadId)->orderByDesc('id')->first(['id', 'status', 'stop_kind', 'stop_summary', 'resume_after_setup_at']);
        $root = DB::table('tasks')->where('thread_id', $threadId)->orderBy('id')->first(['early_warning', 'tagged_co_owner_user_id']);
        $request = DB::table('co_owner_requests')
            ->join('tasks', 'tasks.id', '=', 'co_owner_requests.task_id')
            ->where('tasks.thread_id', $threadId)
            ->orderByDesc('co_owner_requests.id')
            ->first(['co_owner_requests.id', 'co_owner_requests.status', 'co_owner_requests.asked_user_ids']);

        $queued = DB::table('pending_steering_messages')
            ->whereIn('root_task_id', DB::table('tasks')->where('thread_id', $threadId)->select('id'))
            ->whereNotExists(fn ($delivered) => $delivered->from('thread_turn_messages')
                ->where(fn ($match) => $match->whereColumn('thread_turn_messages.pending_steering_message_id', 'pending_steering_messages.id')
                    ->orWhereColumn('thread_turn_messages.uuid', 'pending_steering_messages.uuid')))
            ->orderBy('id')
            ->get(['id', 'mode', 'text'])
            ->map(fn (object $message): array => [$message->id, $message->mode, md5((string) $message->text)])
            ->all();

        $warning = $root?->early_warning === null ? null : (json_decode((string) $root->early_warning, true)['state'] ?? null);

        return substr(md5(json_encode([
            $thread?->repo, $thread?->status, $thread?->slash_commands_captured_at,
            $head?->id, $head?->status, $head?->stop_kind, $head?->stop_summary, $head?->resume_after_setup_at !== null,
            $warning, $root?->tagged_co_owner_user_id,
            $request?->id, $request?->status, $request?->asked_user_ids, $queued,
            ThreadCiChecks::fingerprint($threadId),
        ], JSON_THROW_ON_ERROR)), 0, 12);
    }
}
