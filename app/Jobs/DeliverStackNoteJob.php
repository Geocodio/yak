<?php

namespace App\Jobs;

use App\Models\StackThreadMessage;
use App\Models\Thread;
use App\Services\Stacks\StackMessenger;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers one stored note from a person to a thread of the stack, once. The first run to
 * claim the row delivers it; a failed delivery gives the claim back so the next try delivers.
 */
final class DeliverStackNoteJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public readonly int $messageId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 30];
    }

    public function handle(StackMessenger $messenger): void
    {
        $claimed = StackThreadMessage::query()
            ->whereKey($this->messageId)
            ->whereNull('delivered_at')
            ->whereNotNull('from_user_id')
            ->update(['delivered_at' => now()]);

        if ($claimed === 0) {
            return;
        }

        $message = StackThreadMessage::query()->with('fromUser')->findOrFail($this->messageId);
        $receiver = Thread::query()->withoutGlobalScopes()->find($message->to_thread_id);
        $about = Thread::query()->withoutGlobalScopes()->find($message->from_thread_id);

        if ($receiver === null || $about === null || ! $receiver->isOpen()) {
            Log::info('A stack note has no open thread to go to', ['message' => $this->messageId]);

            return;
        }

        try {
            $messenger->deliver($receiver, StackMessenger::personFrame($message->fromUser, $message->text), $about);
        } catch (Throwable $exception) {
            StackThreadMessage::query()->whereKey($this->messageId)->update(['delivered_at' => null]);

            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('A stack note could not be delivered', ['message' => $this->messageId, 'error' => $exception::class]);
    }
}
