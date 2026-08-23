<?php

namespace App\Jobs;

use App\Models\PendingSteeringMessage;
use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class FlushSteeringMessagesJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 30;

    public function __construct(public readonly int $rootTaskId)
    {
        $this->onQueue('default');
    }

    /**
     * Keyed on the chain root so two Success transitions in the same chain
     * within the delay window collapse into a single flush — otherwise both
     * could read the same un-flushed message set and create duplicate
     * follow-ups (spec requires exactly ONE follow-up per batch).
     *
     * The unique lock also DROPS a second dispatch arriving while a flush is
     * pending — a message landing in that window waits for the chain's next
     * Success transition instead of flushing immediately. That's an accepted
     * tradeoff versus duplicate follow-ups.
     */
    public function uniqueId(): string
    {
        return (string) $this->rootTaskId;
    }

    public function handle(FollowUpTaskFactory $factory): void
    {
        $messages = PendingSteeringMessage::where('root_task_id', $this->rootTaskId)->orderBy('id')->get();

        if ($messages->isEmpty()) {
            return;
        }

        $root = YakTask::find($this->rootTaskId);

        if ($root === null) {
            PendingSteeringMessage::whereIn('id', $messages->pluck('id'))->delete();

            return;
        }

        $instructions = "While you were working, these replies arrived:\n\n"
            . $messages->map(fn (PendingSteeringMessage $m) => '- ' . $m->text)->implode("\n");

        $child = $factory->create($root, $instructions, 'steering');

        if ($child !== null) {
            PendingSteeringMessage::whereIn('id', $messages->pluck('id'))->delete();
        }
    }
}
