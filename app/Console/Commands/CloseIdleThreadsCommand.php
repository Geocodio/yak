<?php

namespace App\Console\Commands;

use App\Enums\TaskStatus;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadStatus;
use App\Models\Thread;
use App\Services\Threads\ThreadCloser;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

#[Signature('yak:close-idle-threads')]
#[Description('Close threads without an open PR that have been idle past the destroy window')]
class CloseIdleThreadsCommand extends Command
{
    public function handle(ThreadCloser $closer): int
    {
        $days = (int) config('yak.threads.destroy_days');
        $closed = 0;

        Thread::withoutGlobalScopes()
            ->where('status', ThreadStatus::Open)
            ->where('owner_side', '!=', ThreadOwnerSide::Device)
            ->whereRaw('coalesce(last_activity_at, created_at) < ?', [now()->subDays($days)])
            ->whereDoesntHave('tasks', fn (Builder $tasks) => $tasks
                ->whereIn('status', TaskStatus::activeValues())
                ->orWhere(fn (Builder $open) => $open->whereNotNull('pr_url')->whereNull('pr_merged_at')->whereNull('pr_closed_at')))
            ->chunkById(100, function ($threads) use ($closer, &$closed): void {
                foreach ($threads as $thread) {
                    $closer->close($thread, 'idle');
                    $closed++;
                }
            });

        $this->components->info("Closed {$closed} idle thread(s).");

        return self::SUCCESS;
    }
}
