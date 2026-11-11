<?php

namespace App\Services\Stacks;

use App\Enums\RepositoryRole;
use App\Enums\TaskMode;
use App\Enums\ThreadKind;
use App\Exceptions\StackRuleViolation;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\ThreadTurns;
use App\YakPromptBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates the sub-thread rows for a spawn. Sandbox and first turn are
 * started separately, so the tool call returns at once.
 */
class ChildSpawner
{
    private const SPAWNABLE_KINDS = [ThreadKind::Research, ThreadKind::Implement, ThreadKind::Mockup, ThreadKind::General];

    public function __construct(
        private readonly StackRules $rules,
        private readonly ChildSandboxProvisioner $provisioner,
        private readonly ResearchChildRunner $researchRunner,
        private readonly ThreadTurns $turns,
        private readonly ChildAwakeLimit $awakeLimit,
        private readonly ChildCardPublisher $cards,
    ) {}

    public function spawn(Thread $parent, string $name, string $brief, ThreadKind $kind, ?string $model, ?TaskRun $spawnedBy = null, ?string $repo = null, ?User $actor = null): Thread
    {
        $this->rules->assertMaySpawn($parent);

        if (! in_array($kind, self::SPAWNABLE_KINDS, true)) {
            throw new StackRuleViolation('A sub-thread must be research, implement, mockup or general.');
        }

        $repo = $this->rules->resolveChildRepo($parent, $kind, $repo);
        $brief = trim($brief);

        if ($brief === '') {
            throw new StackRuleViolation('A sub-thread needs a brief.');
        }

        $allowedModels = config('claude-accounts.models');

        if ($model !== null && ! in_array($model, $allowedModels, true)) {
            throw new StackRuleViolation('Unknown model. Use one of: ' . implode(', ', $allowedModels) . '.');
        }

        $child = DB::transaction(function () use ($parent, $name, $brief, $kind, $model, $spawnedBy, $repo, $actor): Thread {
            $locked = Thread::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($parent->id);
            $root = $locked->rootThread();
            $slug = $this->uniqueName($locked, $name);
            $branch = in_array($kind, [ThreadKind::Implement, ThreadKind::Mockup], true)
                ? 'yak/' . ($locked->branch ?? $locked->name) . '/' . $slug
                : null;

            $child = Thread::query()->create([
                'name' => $slug,
                'title' => Str::limit(trim((string) strtok($brief, "\n")), 120, '...'),
                'repo' => $repo ?? $locked->repo,
                'kind' => $kind,
                'brief' => $brief,
                'visibility' => $root->visibility,
                'owner_user_id' => $root->owner_user_id,
                'created_by_user_id' => $actor->id ?? $root->owner_user_id,
                'parent_thread_id' => $locked->id,
                'root_thread_id' => $root->id,
                'spawned_by_task_run_id' => $spawnedBy?->id,
                'claude_account_id' => $locked->claude_account_id,
                'model' => $model ?? (string) config('yak.sandbox.default_child_model'),
                'branch' => $branch,
                'child_awake_limit' => (int) config('yak.threads.child_awake_limit'),
            ]);

            $primary = $locked->primaryRepository;
            $child->repositories()->create([
                'repo' => $repo ?? $locked->repo,
                'role' => RepositoryRole::Primary,
                'sandbox_path' => $repo !== null
                    ? (string) config('yak.sandbox.workspace_path', '/workspace')
                    : $primary->sandbox_path ?? (string) config('yak.sandbox.workspace_path', '/workspace'),
                'branch' => $branch,
            ]);

            YakTask::query()->create([
                'source' => YakTask::SOURCE_STACK,
                'repo' => $repo ?? $locked->repo,
                'external_id' => 'STACK-' . Str::upper(Str::random(8)),
                'description' => $brief,
                'mode' => $kind === ThreadKind::Research ? TaskMode::Research : TaskMode::Fix,
                'started_by_user_id' => $actor->id ?? $this->driverOf($locked),
                'thread_id' => $child->id,
            ]);

            return $child;
        });

        $this->cards->publish($child);

        return $child;
    }

    /**
     * Gives the child its sandbox and starts its first turn with the wrapped
     * brief. A child that is still waiting for a sandbox stays queued and no
     * turn starts; running this again later resumes it.
     */
    public function start(Thread $child): void
    {
        if ($child->runs()->exists()) {
            return;
        }

        if ($child->kind === ThreadKind::Research) {
            $this->researchRunner->prepare($child);
        } elseif (! $this->awakeLimit->hasFreeSlot($this->parentOf($child))) {
            $child->forceFill(['queued_for_slot_at' => $child->queued_for_slot_at ?? now()])->save();
            $this->cards->publish($child);

            return;
        } else {
            $this->provisioner->provision($child);
        }

        $child->refresh();

        if ($child->queued_for_slot_at !== null) {
            $this->cards->publish($child);

            return;
        }

        $task = $child->tasks()->firstOrFail();
        $child->forceFill(['append_system_prompt' => YakPromptBuilder::systemPrompt($task)])->save();
        $driver = $task->started_by_user_id === null ? null : User::query()->find($task->started_by_user_id);

        $this->turns->start($task, $this->wrapBrief($child), $driver, null);
        $this->cards->publish($child);
    }

    private function wrapBrief(Thread $child): string
    {
        $parent = $this->parentOf($child);

        return "You are a sub-thread named \"{$child->name}\" of the thread \"{$parent->title}\". "
            . 'Your parent is an agent, not a person: it can answer questions from its plan, but it cannot approve anything on a person\'s behalf. '
            . "Do the work below, and when you are done, finish with a clear result for your parent.\n\n"
            . "Brief:\n" . $child->brief;
    }

    private function parentOf(Thread $child): Thread
    {
        return Thread::query()->withoutGlobalScopes()->findOrFail($child->parent_thread_id);
    }

    private function uniqueName(Thread $parent, string $name): string
    {
        $base = Str::limit(Str::slug($name), 40, '') ?: 'thread';
        $candidate = $base;

        for ($suffix = 2; Thread::query()->withoutGlobalScopes()->where('parent_thread_id', $parent->id)->where('name', $candidate)->exists(); $suffix++) {
            $candidate = "{$base}-{$suffix}";
        }

        return $candidate;
    }

    private function driverOf(Thread $parent): ?int
    {
        return $parent->runs()->whereNotNull('driver_user_id')->latest('id')->value('driver_user_id')
            ?? $parent->effectiveOwnerUserId();
    }
}
