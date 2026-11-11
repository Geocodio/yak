<?php

namespace App\Services\Stacks;

use App\Enums\ThreadOwnerSide;
use App\Exceptions\StackRuleViolation;
use App\Models\Thread;

/**
 * Who may spawn and who may talk to whom inside a stack. Every refusal is a
 * StackRuleViolation whose message goes back to Claude as the tool result.
 */
class StackRules
{
    public function assertMaySpawn(Thread $parent): void
    {
        if ($parent->isChild()) {
            throw new StackRuleViolation('Sub-threads cannot spawn their own sub-threads.');
        }

        if (! $parent->isOpen()) {
            throw new StackRuleViolation('This thread is closed, so it cannot spawn sub-threads.');
        }

        if ($parent->owner_side === ThreadOwnerSide::Device) {
            throw new StackRuleViolation('Teleport this session to Yak first.');
        }

        if ($parent->stack_stopped_at !== null) {
            throw new StackRuleViolation('This stack was stopped, so it cannot spawn sub-threads.');
        }
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
