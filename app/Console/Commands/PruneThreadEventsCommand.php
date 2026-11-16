<?php

namespace App\Console\Commands;

use App\Enums\ThreadStatus;
use App\Models\Thread;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('yak:prune-thread-events')]
#[Description('Delete stored realtime events of threads closed past the retention window')]
class PruneThreadEventsCommand extends Command
{
    public function handle(): int
    {
        $days = (int) config('yak.threads.session_retention_days');

        $deleted = DB::table('thread_events')
            ->whereIn('thread_id', Thread::withoutGlobalScopes()
                ->select('id')
                ->where('status', ThreadStatus::Closed)
                ->where('closed_at', '<', now()->subDays($days)))
            ->delete();

        $this->components->info("Pruned {$deleted} event(s) of threads closed more than {$days} days ago.");

        return self::SUCCESS;
    }
}
