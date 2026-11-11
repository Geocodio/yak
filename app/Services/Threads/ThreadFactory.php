<?php

namespace App\Services\Threads;

use App\Enums\RepositoryRole;
use App\Enums\ThreadSessionKind;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;
use App\Services\SessionStore\SessionSync;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ThreadFactory
{
    public function __construct(
        private readonly ClaudeAccountResolver $resolver,
        private readonly SessionSync $sessionSync,
    ) {}

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
     * The thread `/clear` starts: same repository and branch, a fresh account
     * resolution, and no session to resume.
     */
    public function continuing(Thread $previous, YakTask $task): Thread
    {
        return DB::transaction(function () use ($previous, $task): Thread {
            $thread = $this->create($task->repo, $task, $previous->branch, $previous->id);
            $task->forceFill(['thread_id' => $thread->id])->save();

            return $thread;
        });
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

            $seedsFromRoot = $locked->thread_id === null;
            $thread = $this->create($locked->repo, $locked, $seedsFromRoot ? $locked->branch_name : null, null);

            if ($seedsFromRoot && $locked->session_id !== null) {
                $thread->sessions()->create(['session_id' => $locked->session_id, 'kind' => ThreadSessionKind::Main]);
                $this->sessionSync->seedFromLegacyTranscript($thread, $locked->session_id);
            }

            $locked->forceFill(['thread_id' => $thread->id])->save();

            return $thread->id;
        });
    }

    private function create(string $repo, YakTask $root, ?string $branch, ?int $continuedFromThreadId): Thread
    {
        $starter = $root->started_by_user_id === null ? null : User::query()->find($root->started_by_user_id);
        $resolved = $this->resolver->forNewThread($starter, $repo, null, null);
        $title = Str::limit($root->headline() !== '' ? $root->headline() : $repo, 120, '');

        $thread = Thread::query()->create([
            'name' => Str::limit(Str::slug($title), 40, '') . '-' . Str::lower(Str::random(6)),
            'title' => $title,
            'repo' => $repo,
            'created_by_user_id' => $root->started_by_user_id,
            'continued_from_thread_id' => $continuedFromThreadId,
            'claude_account_id' => $resolved->account?->id,
            'model' => $resolved->model,
            'branch' => $branch,
        ]);

        $thread->repositories()->create(['repo' => $repo, 'role' => RepositoryRole::Primary, 'sandbox_path' => (string) config('yak.sandbox.workspace_path', '/workspace'), 'branch' => $branch]);

        return $thread;
    }
}
