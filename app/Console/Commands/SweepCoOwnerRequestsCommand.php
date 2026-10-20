<?php

namespace App\Console\Commands;

use App\Enums\CoOwnerRequestStatus;
use App\Jobs\SendCoOwnerMessagesJob;
use App\Models\CoOwnerRequest;
use App\Services\CoOwner\CoOwnerDecisionException;
use App\Services\CoOwner\CoOwnerDecisions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('yak:co-owner:sweep')]
#[Description('Expire co-owner requests after seven days and remind the ones held for two days')]
class SweepCoOwnerRequestsCommand extends Command
{
    private const REMINDER_AFTER_DAYS = 2;

    /**
     * Expiry runs first, so a request that expires in this pass gets no reminder.
     */
    public function handle(CoOwnerDecisions $decisions): int
    {
        $expired = CoOwnerRequest::where('status', CoOwnerRequestStatus::Pending)->where('expires_at', '<=', now())->get();

        foreach ($expired as $request) {
            try {
                $decisions->expire($request);
            } catch (CoOwnerDecisionException) {
                // Decided between the query and the lock.
            }
        }

        $due = CoOwnerRequest::where('status', CoOwnerRequestStatus::Pending)
            ->whereNull('reminded_at')
            ->where('held_at', '<=', now()->subDays(self::REMINDER_AFTER_DAYS))
            ->get();

        foreach ($due as $request) {
            $isClaimed = CoOwnerRequest::whereKey($request->id)
                ->where('status', CoOwnerRequestStatus::Pending)
                ->whereNull('reminded_at')
                ->update(['reminded_at' => now()]) === 1;

            if ($isClaimed) {
                SendCoOwnerMessagesJob::dispatch($request, 'reminder');
            }
        }

        $this->components->info("Expired {$expired->count()} and reminded {$due->count()} co-owner request(s).");

        return self::SUCCESS;
    }
}
