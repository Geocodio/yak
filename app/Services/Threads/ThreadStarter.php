<?php

namespace App\Services\Threads;

use App\Enums\RepositoryRole;
use App\Enums\TaskMode;
use App\Enums\ThreadKind;
use App\Jobs\RunYakJob;
use App\Models\TaskAttachment;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\AgentJobDispatcher;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;
use App\Services\Threads\Repos\RepositoryAccess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Creates a thread from the start screen: the thread, its primary repository,
 * the root task, and the before-turn job that writes the first turn.
 */
class ThreadStarter
{
    public function __construct(
        private readonly ClaudeAccountResolver $resolver,
        private readonly AgentJobDispatcher $dispatcher,
        private readonly RepositoryAccess $access,
    ) {}

    /**
     * @param  Collection<int, TaskAttachment>  $attachments  already stored, not yet tied to a task
     * @param  list<array{repo: string, role: string}>  $extraRepositories  research mode treats every extra as context
     *
     * @throws ValidationException when an extra is the primary, repeated, or not reachable by the GitHub App
     * @throws RuntimeException when no usable account exists, or the chosen one is not the starter's to use
     */
    public function start(
        User $starter,
        string $repo,
        TaskMode $mode,
        string $description,
        ?int $accountId,
        ?string $model,
        Collection $attachments = new Collection,
        array $extraRepositories = [],
    ): Thread {
        $this->assertExtrasAllowed($repo, $extraRepositories);

        $resolved = $this->resolver->forNewThread($starter, $repo, $accountId, $model);

        if ($resolved->account === null) {
            throw new RuntimeException('No usable Claude account. Connect one in Settings, Claude accounts, or ask an admin to set up a shared account.');
        }

        if ($accountId !== null && $resolved->account->id !== $accountId) {
            throw new RuntimeException('That Claude account is not available to you. Pick one of your own or a shared account.');
        }

        $description = trim($description);

        $task = DB::transaction(function () use ($starter, $repo, $mode, $description, $resolved, $attachments, $extraRepositories): YakTask {
            $thread = Thread::query()->create([
                'name' => Str::slug(Str::limit($description, 40, '')) . '-' . Str::lower(Str::random(6)),
                'title' => Str::limit(trim((string) strtok($description, "\n")), 120, '...'),
                'repo' => $repo,
                'kind' => ThreadKind::General,
                'created_by_user_id' => $starter->id,
                'owner_user_id' => $starter->id,
                'claude_account_id' => $resolved->account->id,
                'model' => $resolved->model,
            ]);

            ThreadRepository::query()->create(['thread_id' => $thread->id, 'repo' => $repo, 'role' => RepositoryRole::Primary, 'sandbox_path' => ThreadRepository::sandboxPathFor($repo, RepositoryRole::Primary)]);

            foreach ($extraRepositories as $extra) {
                $role = $mode === TaskMode::Research ? RepositoryRole::Context : RepositoryRole::from($extra['role']);
                ThreadRepository::query()->create([
                    'thread_id' => $thread->id,
                    'repo' => $extra['repo'],
                    'role' => $role,
                    'sandbox_path' => ThreadRepository::sandboxPathFor($extra['repo'], $role),
                    'added_by' => 'start',
                ]);
            }

            $task = YakTask::query()->create([
                'source' => 'dashboard',
                'repo' => $repo,
                'external_id' => 'DASH-' . Str::upper(Str::random(8)),
                'description' => $description,
                'mode' => $mode,
                'author_name' => $starter->name,
                'started_by_user_id' => $starter->id,
                'thread_id' => $thread->id,
            ]);

            $attachments->each(fn (TaskAttachment $attachment) => $attachment->update(['yak_task_id' => $task->id]));

            return $task;
        });

        // The before-turn job prepares the branch in the thread's sandbox and writes the first turn.
        $this->dispatcher->dispatch($task, RunYakJob::class);

        return $task->thread()->firstOrFail();
    }

    /**
     * @param  list<array{repo: string, role: string}>  $extraRepositories
     */
    private function assertExtrasAllowed(string $primary, array $extraRepositories): void
    {
        $seen = [$primary];

        foreach ($extraRepositories as $index => $extra) {
            $reason = in_array($extra['repo'], $seen, true)
                ? "{$extra['repo']} is already part of this thread."
                : $this->access->check($extra['repo'])->reason;

            if ($reason !== null) {
                throw ValidationException::withMessages(["repositories.{$index}.repo" => $reason]);
            }

            $seen[] = $extra['repo'];
        }
    }
}
