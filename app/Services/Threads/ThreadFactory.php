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
     * joins the thread of its conversation's root.
     */
    public function forTask(YakTask $task): Thread
    {
        if ($task->thread_id !== null) {
            return Thread::query()->findOrFail($task->thread_id);
        }

        $root = $task->rootTask();

        if ($root->thread_id === null) {
            $this->createFor($root);
        }

        if ($task->isNot($root)) {
            $task->forceFill(['thread_id' => $root->thread_id])->save();
        }

        return Thread::query()->findOrFail($task->thread_id);
    }

    private function createFor(YakTask $root): void
    {
        DB::transaction(function () use ($root): void {
            $resolved = $this->resolver->forNewThread($root);

            $thread = Thread::query()->create([
                'repo' => $root->repo,
                'created_by_user_id' => $root->started_by_user_id,
                'claude_account_id' => $resolved->account?->id,
                'model' => $resolved->model,
            ]);

            $thread->repositories()->create(['repo' => $root->repo, 'role' => 'primary']);

            if ($root->session_id !== null) {
                $thread->sessions()->create(['session_id' => $root->session_id, 'kind' => ThreadSessionKind::Main]);
                $this->sessionSync->seedFromLegacyTranscript($thread, $root->session_id);
            }

            $root->forceFill(['thread_id' => $thread->id])->save();
        });
    }
}
