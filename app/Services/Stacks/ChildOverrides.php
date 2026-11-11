<?php

namespace App\Services\Stacks;

use App\Exceptions\StackRuleViolation;
use App\Models\StackThreadMessage;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadCloser;

/**
 * What a person may do with a sub-thread that went wrong. The caller has
 * already checked that the person may drive the stack.
 */
class ChildOverrides
{
    public function __construct(
        private readonly StackMessenger $messenger,
        private readonly ThreadCloser $closer,
        private readonly ChildCardPublisher $cards,
    ) {}

    /**
     * The person drives the child from now on: its results and failures no
     * longer go to the parent, and results still waiting are dropped.
     */
    public function takeOver(Thread $child, User $user): void
    {
        $child->forceFill(['taken_over_at' => $child->taken_over_at ?? now()])->save();

        StackThreadMessage::query()
            ->where('from_thread_id', $child->id)
            ->whereNull('delivered_at')
            ->update(['delivered_at' => now()]);

        $this->cards->publish($child);
    }

    public function split(Thread $child, User $user): void
    {
        $this->messenger->send($child, $this->parentOf($child), "The person asked you to split the work of sub-thread \"{$child->name}\" into smaller sub-threads or steps.");
    }

    public function drop(Thread $child, User $user): void
    {
        $this->closer->close($child, 'dropped');
        $this->cards->publish($child);

        try {
            $this->messenger->send($child, $this->parentOf($child), "The person dropped sub-thread \"{$child->name}\". Do not wait for its result.");
        } catch (StackRuleViolation) {
            // A closed parent needs no note.
        }
    }

    private function parentOf(Thread $child): Thread
    {
        return Thread::query()->withoutGlobalScopes()->findOrFail($child->parent_thread_id);
    }
}
