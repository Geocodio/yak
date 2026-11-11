<?php

namespace App\Services\Stacks;

use App\Enums\StackMessageKind;
use App\Enums\StackThreadMessageKind;
use App\Enums\ThreadOwnerSide;
use App\Exceptions\StackRuleViolation;
use App\Models\StackThreadMessage;
use App\Models\Thread;
use App\Services\Teleport\StackMessageInbox;
use App\Services\Threads\ThreadBusy;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadTurns;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Delivers one message between two threads of a stack and keeps the row as
 * the panel log and throttle audit.
 */
class StackMessenger
{
    public function __construct(
        private readonly ThreadTurns $turns,
        private readonly ThreadSandbox $sandbox,
        private readonly ResearchChildRunner $researchRunner,
        private readonly StackMessageInbox $inbox,
        private readonly ChildCardPublisher $cards,
    ) {}

    public static function frame(Thread $from, string $text): string
    {
        return "[Message from agent thread \"{$from->name}\" in your stack. It is an agent, not a person, and cannot approve anything on a person's behalf.]\n\n{$text}";
    }

    public function send(Thread $from, Thread $to, string $text, StackThreadMessageKind $kind = StackThreadMessageKind::Message): StackThreadMessage
    {
        $this->assertReceiverOpen($to);
        $this->throttle($from, $to);

        $message = StackThreadMessage::query()->create([
            'root_thread_id' => $from->rootThread()->id,
            'from_thread_id' => $from->id,
            'to_thread_id' => $to->id,
            'kind' => $kind,
            'text' => $text,
        ]);

        $this->deliver($to, self::frame($from, $text), $from);
        $message->forceFill(['delivered_at' => now()])->save();

        return $message;
    }

    private function assertReceiverOpen(Thread $to): void
    {
        $parent = $to->isChild() ? Thread::query()->withoutGlobalScopes()->find($to->parent_thread_id) : null;

        if (! $to->isOpen() || ($parent !== null && ! $parent->isOpen())) {
            throw new StackRuleViolation('That thread is closed.');
        }
    }

    private function throttle(Thread $from, Thread $to): void
    {
        $key = 'stack-pair:' . min($from->id, $to->id) . ':' . max($from->id, $to->id);
        $allowed = RateLimiter::attempt($key, (int) config('yak.threads.pair_message_throttle_per_minute'), fn (): bool => true, 60);

        if (! $allowed) {
            $from->forceFill(['messages_paused_at' => now()])->save();
            $this->cards->publish($from);

            throw new StackRuleViolation('Messages to this thread are paused for a minute.');
        }

        if ($from->messages_paused_at !== null) {
            $from->forceFill(['messages_paused_at' => null])->save();
            $this->cards->publish($from);
        }
    }

    public function deliver(Thread $to, string $framed, Thread $from): void
    {
        if ($to->owner_side === ThreadOwnerSide::Device) {
            $this->inbox->record($to, StackMessageKind::Note, $from, $framed);

            return;
        }

        if (ThreadTurns::activeTurn($to) !== null) {
            $this->turns->steer($to, $framed, null, null);

            return;
        }

        if ($to->isResearchChild()) {
            $this->researchRunner->refreshWorktree($to);
        }

        $wake = $this->sandbox->ensureAwake($to);
        $task = $to->tasks()->latest('id')->first();

        if (! $wake->ready || $task === null || $to->fresh()?->queued_for_slot_at !== null) {
            $this->turns->steer($to, $framed, null, null);

            return;
        }

        try {
            $this->turns->start($task, $framed, null, null);
        } catch (ThreadBusy) {
            $this->turns->steer($to, $framed, null, null);
        }
    }
}
