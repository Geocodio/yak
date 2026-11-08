<?php

namespace App\Services\Threads;

use App\Enums\ThreadSessionKind;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;
use App\Services\SessionStore\SessionSync;
use Illuminate\Support\Facades\DB;

class ThreadFactory
{
    public function __construct(private readonly ClaudeAccountResolver $resolver, private readonly SessionSync $sessionSync) {}

    /**
     * The thread a task belongs to: a root task starts one, a follow-up
     * joins the thread of its conversation's root. A closed thread never
     * reopens, so a task whose thread is closed continues in a new thread
     * with a fresh account resolution and no session to resume.
     */
    public function forTask(YakTask $task): Thread
    {
        if ($task->thread_id !== null) {
            $thread = Thread::query()->findOrFail($task->thread_id);

            if ($thread->isOpen()) {
                return $thread;
            }
        }

        $threadId = $this->openThreadIdOf($task->rootTask());

        if ($task->thread_id !== $threadId) {
            $task->forceFill(['thread_id' => $threadId])->save();
        }

        return Thread::query()->findOrFail($threadId);
    }

    /**
     * The root's open thread, created when the root has none or its thread
     * is closed. The root row is locked while it is read, so two tasks of one
     * conversation cannot each create a thread.
     */
    private function openThreadIdOf(YakTask $root): int
    {
        return DB::transaction(function () use ($root): int {
            $locked = YakTask::query()->lockForUpdate()->findOrFail($root->id);
            $current = $locked->thread_id === null ? null : Thread::query()->find($locked->thread_id);

            if ($current?->isOpen() === true) {
                return $current->id;
            }

            return $this->createFor($locked)->id;
        });
    }

    private function createFor(YakTask $root): Thread
    {
        $resolved = $this->resolver->forNewThread($root);

        $thread = Thread::query()->create([
            'repo' => $root->repo,
            'created_by_user_id' => $root->started_by_user_id,
            'claude_account_id' => $resolved->account?->id,
            'model' => $resolved->model,
        ]);

        $thread->repositories()->create(['repo' => $root->repo, 'role' => 'primary']);

        if ($root->thread_id === null && $root->session_id !== null) {
            $thread->sessions()->create(['session_id' => $root->session_id, 'kind' => ThreadSessionKind::Main]);
            $this->sessionSync->seedFromLegacyTranscript($thread, $root->session_id);
        }

        $root->forceFill(['thread_id' => $thread->id])->save();

        return $thread;
    }
}
