<?php

namespace App\Services\Threads;

use App\Enums\RepositoryRole;
use App\Enums\ThreadSessionKind;
use App\Models\Thread;
use App\Models\ThreadShare;
use App\Models\User;
use App\Models\YakTask;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;
use App\Services\SessionStore\SessionSync;
use App\Support\AttachmentReferences;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ThreadFactory
{
    public function __construct(
        private readonly ClaudeAccountResolver $resolver,
        private readonly SessionSync $sessionSync,
        private readonly ThreadOrigin $origin,
        private readonly ThreadCloser $closer,
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
            $task->withoutTimestamps(fn () => $task->forceFill(['thread_id' => $threadId])->save());
        }

        return Thread::query()->findOrFail($threadId);
    }

    /**
     * The thread of a request whose repository is not known yet: `repo` is
     * the unknown marker, there is no repository row and no sandbox, and
     * `attachRepository` completes it once the repository is chosen.
     */
    public function forRequestWithoutRepository(YakTask $root): Thread
    {
        return DB::transaction(function () use ($root): Thread {
            $thread = $this->create(Thread::UNKNOWN_REPOSITORY, $root, null, null);
            $root->withoutTimestamps(fn () => $root->forceFill(['thread_id' => $thread->id])->save());

            return $thread;
        });
    }

    /**
     * Gives a thread without a repository its primary repository. A thread
     * that has not run yet also resolves its Claude account again, because
     * the account choice depends on the repository.
     */
    public function attachRepository(Thread $thread, string $repo): void
    {
        DB::transaction(function () use ($thread, $repo): void {
            $locked = Thread::query()->lockForUpdate()->findOrFail($thread->id);

            if ($locked->hasRepository()) {
                return;
            }

            $locked->repo = $repo;

            if (! $locked->runs()->exists()) {
                $starter = $locked->created_by_user_id === null ? null : User::query()->find($locked->created_by_user_id);
                $resolved = $this->resolver->forNewThread($starter, $repo, null, null);
                $locked->forceFill(['claude_account_id' => $resolved->account?->id, 'model' => $resolved->model, 'effort' => $resolved->effort]);
            }

            $locked->save();
            $this->addPrimaryRepository($locked, $locked->branch);
            $thread->setRawAttributes($locked->getAttributes(), true);
        });
    }

    /**
     * The thread `/clear` starts: same repository and branch, a fresh account
     * resolution, the same visibility and owner, and no session to resume.
     */
    public function continuing(Thread $previous, YakTask $task): Thread
    {
        return DB::transaction(function () use ($previous, $task): Thread {
            $thread = $this->create($task->repo, $task, $previous->branch, $previous->id);
            $thread->forceFill([
                'visibility' => $previous->effectiveVisibility(),
                'owner_user_id' => $previous->effectiveOwnerUserId(),
            ])->save();
            $task->forceFill(['thread_id' => $thread->id])->save();

            return $thread;
        });
    }

    /**
     * Moves a request to another repository without losing its page: the
     * thread closes, a thread in the new repository continues it with the
     * same origin, and the conversation's tasks follow. Past runs stay on
     * the closed thread. The unknown marker moves the request into a thread
     * that waits for its repository.
     */
    public function moved(Thread $from, YakTask $task, string $repo): Thread
    {
        $thread = DB::transaction(function () use ($from, $task, $repo): Thread {
            $lockedFrom = Thread::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($from->id);
            $root = $task->rootTask();
            $thread = $this->create($repo, $root, null, $lockedFrom->id);
            $thread->forceFill([
                'visibility' => $lockedFrom->effectiveVisibility(),
                'owner_user_id' => $lockedFrom->effectiveOwnerUserId(),
            ])->save();

            ThreadShare::query()->where('thread_id', $lockedFrom->root_thread_id ?? $lockedFrom->id)->pluck('user_id')
                ->each(fn (int $userId) => ThreadShare::query()->create(['thread_id' => $thread->id, 'user_id' => $userId]));

            YakTask::query()->withoutGlobalScopes()->where('thread_id', $lockedFrom->id)->whereIn('id', $root->conversation()->pluck('id'))->lockForUpdate()->get()
                ->each(fn (YakTask $member) => $member->withoutTimestamps(fn () => $member->forceFill(['thread_id' => $thread->id])->save()));

            $lockedFrom->forceFill(['close_reason' => 'moved'])->save();

            return $thread;
        });

        try {
            $this->closer->close($from, 'moved');
        } catch (\Throwable $exception) {
            report($exception);
        }

        $task->setAttribute('thread_id', $thread->id);
        $task->syncOriginalAttribute('thread_id');

        return $thread;
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

            $continuesLegacy = $current?->is_legacy === true;
            $seedsFromRoot = $locked->thread_id === null || $continuesLegacy;
            $thread = $this->create($locked->repo, $locked, $seedsFromRoot ? $locked->branch_name : null, $continuesLegacy ? $current->id : null);

            if ($continuesLegacy) {
                $thread->forceFill([
                    'visibility' => $current->effectiveVisibility(),
                    'owner_user_id' => $current->effectiveOwnerUserId(),
                ])->save();
                YakTask::query()->withoutGlobalScopes()->where('thread_id', $current->id)->whereIn('id', $locked->conversation()->pluck('id'))
                    ->toBase()->update(['thread_id' => $thread->id]);
            }

            if ($seedsFromRoot && $locked->session_id !== null) {
                // A missing transcript means the session is gone (a review's sandbox is destroyed),
                // so the main session is only recorded when it can be resumed; otherwise the thread starts fresh.
                if ($this->sessionSync->seedFromLegacyTranscript($thread, $locked->session_id)) {
                    $thread->sessions()->create(['session_id' => $locked->session_id, 'kind' => ThreadSessionKind::Main]);
                }
            }

            $locked->withoutTimestamps(fn () => $locked->forceFill(['thread_id' => $thread->id])->save());

            return $thread->id;
        });
    }

    private function create(string $repo, YakTask $root, ?string $branch, ?int $continuedFromThreadId): Thread
    {
        $starter = $root->started_by_user_id === null ? null : User::query()->find($root->started_by_user_id);
        $resolved = $this->resolver->forNewThread($starter, $repo, null, null);
        $headline = AttachmentReferences::stripFromText($root->headline());
        $title = Str::limit($headline !== '' ? $headline : $repo, 120, '');

        $thread = Thread::query()->create([
            'name' => Str::limit(Str::slug($title), 40, '') . '-' . Str::lower(Str::random(6)),
            'title' => $title,
            'repo' => $repo,
            'created_by_user_id' => $root->started_by_user_id,
            'continued_from_thread_id' => $continuedFromThreadId,
            'claude_account_id' => $resolved->account?->id,
            'model' => $resolved->model,
            'effort' => $resolved->effort,
            'branch' => $branch,
            ...$this->origin->forRequest($root),
        ]);

        if ($thread->hasRepository()) {
            $this->addPrimaryRepository($thread, $branch);
        }

        return $thread;
    }

    private function addPrimaryRepository(Thread $thread, ?string $branch): void
    {
        $thread->repositories()->create(['repo' => $thread->repo, 'role' => RepositoryRole::Primary, 'sandbox_path' => (string) config('yak.sandbox.workspace_path', '/workspace'), 'branch' => $branch]);
    }
}
