<?php

namespace App\Services\Threads;

use App\Enums\ThreadKind;
use App\Enums\ThreadSandboxState;
use App\Models\TaskAttachment;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Threads\Contracts\BrokerStreams;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Removes a thread and its sub-threads from Yak: sandboxes, runs, tasks and
 * attachments go, and the session files, events and diffs follow by cascade.
 * The GitHub branch and any pull request are never touched.
 */
class ThreadDeleter
{
    public function __construct(
        private readonly ThreadSandbox $sandbox,
        private readonly BrokerStreams $streams,
    ) {}

    /**
     * @throws RuntimeException when a sandbox could not be destroyed, so no row is removed while a container would be left behind
     */
    public function delete(Thread $thread): void
    {
        // Research sub-threads go first: they borrow the parent's sandbox.
        $threads = Thread::query()->withoutGlobalScopes()
            ->where('parent_thread_id', $thread->id)
            ->get()
            ->sortBy(fn (Thread $child): string => ($child->kind === ThreadKind::Research ? '0' : '1') . str_pad((string) $child->id, 12, '0', STR_PAD_LEFT))
            ->push($thread);

        foreach ($threads as $member) {
            $this->sandbox->destroy($member, preserveWork: false);

            if (! in_array($member->refresh()->sandbox_state, [ThreadSandboxState::None, ThreadSandboxState::Destroyed], true)) {
                throw new RuntimeException('The sandbox could not be destroyed yet. Try again in a moment.');
            }
        }

        $ids = $threads->pluck('id')->all();

        DB::transaction(function () use ($ids): void {
            $taskIds = YakTask::query()->whereIn('thread_id', $ids)->pluck('id');

            TaskAttachment::query()->whereIn('yak_task_id', $taskIds)->get()->each->delete();
            TaskRun::query()->whereIn('thread_id', $ids)->delete();
            YakTask::query()->whereIn('id', $taskIds)->delete();
            Thread::query()->withoutGlobalScopes()->whereIn('id', $ids)->delete();
        });

        foreach ($ids as $id) {
            $this->streams->unregister("threads:{$id}");
            $this->streams->delete("threads:{$id}");
        }
    }
}
