<?php

namespace App\Services\Stacks;

use App\Enums\ThreadKind;
use App\Enums\ThreadOwnerSide;
use App\Exceptions\StackRuleViolation;
use App\Exceptions\ThreadActionRefused;
use App\Models\Repository;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\Repos\RepositoryAccess;
use App\Services\Threads\ThreadAccess;

/**
 * Who may spawn and who may talk to whom inside a stack. Every refusal is a
 * StackRuleViolation whose message goes back to Claude as the tool result.
 */
class StackRules
{
    public function assertMaySpawn(Thread $parent): void
    {
        $violation = $this->spawnViolation($parent);

        if ($violation !== null) {
            throw $violation;
        }
    }

    /**
     * The refusal a person sees for adding a sub-thread to `$parent`, or null
     * when it is allowed. Shares its rule list with `assertMaySpawn()`.
     */
    public function refusalForSpawn(Thread $parent, ?User $actor): ?ThreadActionRefused
    {
        if (! app(ThreadAccess::class)->canDrive($actor, $parent->rootThread())) {
            return new ThreadActionRefused('forbidden', 'You do not have access to this.', [], 403);
        }

        return $this->spawnViolation($parent)?->toRefusal();
    }

    private function spawnViolation(Thread $parent): ?StackRuleViolation
    {
        if ($parent->isChild()) {
            return new StackRuleViolation(
                'Sub-threads cannot spawn their own sub-threads.',
                ThreadActionRefused::SUB_THREAD_CANNOT_SPAWN,
                'A sub-thread cannot start sub-threads of its own. Add it to the parent thread instead.',
                ['parentId' => $parent->rootThread()->public_id],
            );
        }

        if (! $parent->hasRepository()) {
            return new StackRuleViolation(
                'This thread has no repository yet, so it cannot spawn sub-threads.',
                ThreadActionRefused::NO_REPOSITORY,
                ThreadActionRefused::NO_REPOSITORY_SENTENCE,
            );
        }

        if (! $parent->isOpen()) {
            return new StackRuleViolation(
                'This thread is closed, so it cannot spawn sub-threads.',
                ThreadActionRefused::THREAD_CLOSED,
                'This thread is closed, so it cannot start sub-threads.',
            );
        }

        if ($parent->owner_side === ThreadOwnerSide::Device) {
            return new StackRuleViolation(
                'Teleport this session to Yak first.',
                ThreadActionRefused::THREAD_OWNED_BY_DEVICE,
                'Your computer is working on this thread. Hand it back to Yak before adding a sub-thread.',
            );
        }

        if ($parent->stack_stopped_at !== null) {
            return new StackRuleViolation(
                'This stack was stopped, so it cannot spawn sub-threads.',
                ThreadActionRefused::STACK_STOPPED,
                'This stack was stopped, so it cannot start sub-threads.',
            );
        }

        $maxChildren = (int) config('yak.threads.max_children');
        $count = Thread::query()->withoutGlobalScopes()->where('parent_thread_id', $parent->id)->count();

        if ($count >= $maxChildren) {
            return new StackRuleViolation(
                "A thread can have at most {$maxChildren} sub-threads.",
                ThreadActionRefused::STACK_FULL,
                "This thread has started {$count} of {$maxChildren} sub-threads, the most one thread can have.",
                ['count' => $count, 'max' => $maxChildren],
            );
        }

        return null;
    }

    /**
     * The slug of the other repository a child should build in, or null when
     * the child stays in the parent's repo.
     */
    public function resolveChildRepo(Thread $parent, ThreadKind $kind, ?string $repo): ?string
    {
        $repo = trim((string) $repo);

        if ($repo === '') {
            return null;
        }

        if (! in_array($kind, [ThreadKind::Implement, ThreadKind::Research], true)) {
            throw new StackRuleViolation('Only implement and research sub-threads take a repo.', personMessage: 'Only an implement or research sub-thread works in a repository of its own.', field: 'repo');
        }

        $slug = Repository::query()->where('slug', $repo)->orWhere('github_full_name', $repo)->value('slug');

        if (! is_string($slug)) {
            throw new StackRuleViolation("Yak cannot reach {$repo}. Add the repository to the Yak GitHub app first.", personMessage: "Yak cannot reach {$repo}. Ask an admin to add the repository to Yak's GitHub app.", field: 'repo');
        }

        if ($slug === $parent->repo) {
            return null;
        }

        $access = app(RepositoryAccess::class)->check($slug);

        if (! $access->allowed) {
            throw new StackRuleViolation("Yak cannot use {$slug} here. {$access->reason}", personMessage: (string) $access->reason, field: 'repo');
        }

        return $slug;
    }

    public function inSameStack(Thread $first, Thread $second): bool
    {
        return $this->rootId($first) === $this->rootId($second);
    }

    /**
     * Finds a thread of the sender's stack by name, `parent` or id.
     */
    public function resolveTarget(Thread $from, string $to): Thread
    {
        $to = trim($to);
        $stack = Thread::query()->withoutGlobalScopes()
            ->where(fn ($query) => $query->whereKey($this->rootId($from))->orWhere('root_thread_id', $this->rootId($from)));

        if ($to === 'parent') {
            $target = $from->isChild() ? (clone $stack)->whereKey($from->parent_thread_id)->first() : null;
        } elseif (ctype_digit($to)) {
            $target = (clone $stack)->whereKey((int) $to)->first();
        } else {
            $target = (clone $stack)->where('name', $to)->first() ?? (clone $stack)->whereNull('parent_thread_id')->where('title', $to)->first();
        }

        return $target ?? throw new StackRuleViolation("No thread \"{$to}\" in this stack. Use list_threads to see the stack.");
    }

    public function assertMaySend(Thread $from, Thread $to): void
    {
        if ($from->is($to)) {
            throw new StackRuleViolation('A thread cannot message itself.');
        }

        if (! $this->inSameStack($from, $to)) {
            throw new StackRuleViolation('That thread is not in this stack.');
        }

        if (! $to->isOpen()) {
            throw new StackRuleViolation('That thread is closed.');
        }
    }

    private function rootId(Thread $thread): int
    {
        return $thread->root_thread_id ?? $thread->id;
    }
}
