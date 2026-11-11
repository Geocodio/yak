<?php

namespace App\Console\Commands;

use App\Enums\ThreadStatus;
use App\Models\SessionFile;
use App\Models\Thread;
use App\Services\SessionStore\SessionStore;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('yak:prune-session-store')]
#[Description('Delete stored session files of threads closed past the retention window')]
class PruneSessionStoreCommand extends Command
{
    public function handle(SessionStore $store): int
    {
        $days = (int) config('yak.threads.session_retention_days');
        $pruned = 0;

        Thread::withoutGlobalScopes()
            ->where('status', ThreadStatus::Closed)
            ->where('closed_at', '<', now()->subDays($days))
            ->whereIn('id', SessionFile::query()->select('thread_id'))
            ->chunkById(100, function ($threads) use ($store, &$pruned): void {
                foreach ($threads as $thread) {
                    $store->deleteThread($thread);
                    $pruned++;
                }
            });

        $this->components->info("Pruned the session files of {$pruned} thread(s) closed more than {$days} days ago.");

        return self::SUCCESS;
    }
}
