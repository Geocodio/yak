<?php

namespace App\Jobs;

use App\Channels\Slack\PreviewRepairMessenger;
use App\Models\PreviewRepair;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sends a preview repair's messages off the path that triggered them: the
 * pull request opening or the reminder sweep.
 */
class SendPreviewRepairMessagesJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param  'repair_opened'|'verdict'|'reminder'  $event
     */
    public function __construct(
        public int $previewRepairId,
        public string $event,
    ) {
        $this->onQueue('default');
    }

    public function handle(PreviewRepairMessenger $messenger): void
    {
        $previewRepair = PreviewRepair::with(['repository', 'task'])->find($this->previewRepairId);

        if ($previewRepair === null) {
            return;
        }

        match ($this->event) {
            'repair_opened' => $messenger->repairOpened($previewRepair),
            'verdict' => $messenger->verdictReached($previewRepair),
            'reminder' => $messenger->reminder($previewRepair),
            default => null,
        };
    }
}
