<?php

namespace App\Console\Commands;

use App\Enums\PreviewRepairVerdict;
use App\Jobs\SendPreviewRepairMessagesJob;
use App\Models\PreviewRepair;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('yak:preview-repair:sweep')]
#[Description('Remind people when a repair pull request is still open and previews keep failing from its cause')]
class SweepPreviewRepairsCommand extends Command
{
    public const REMINDER_AFTER_DAYS = 3;

    /**
     * A repair is due when its pull request is still open, a preview failed
     * since the last notice, and that notice is at least three days old.
     * Each repair is claimed by moving its last notice forward, so two
     * sweeps never remind for the same repair twice.
     */
    public function handle(): int
    {
        $due = PreviewRepair::query()
            ->live()
            ->where('verdict', PreviewRepairVerdict::Recipe->value)
            ->whereNotNull('last_notified_at')
            ->where('last_notified_at', '<=', now()->subDays(self::REMINDER_AFTER_DAYS))
            ->whereColumn('last_failed_at', '>', 'last_notified_at')
            ->get();

        $reminded = 0;

        foreach ($due as $previewRepair) {
            $isClaimed = PreviewRepair::query()
                ->whereKey($previewRepair->id)
                ->where('last_notified_at', $previewRepair->getRawOriginal('last_notified_at'))
                ->update(['last_notified_at' => now()]) === 1;

            if ($isClaimed) {
                SendPreviewRepairMessagesJob::dispatch($previewRepair->id, 'reminder');
                $reminded++;
            }
        }

        $this->components->info("Reminded {$reminded} preview repair(s).");

        return self::SUCCESS;
    }
}
