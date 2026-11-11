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
#[Description('Close threads that are idle or whose non-PR work has ended')]
class CloseIdleThreadsCommand extends Command
{
    /**
     * Two passes. Idle: no activity past the destroy window. Terminal: every
     * task finished more than terminal_close_hours ago with no PR and no
     * branch to resume on. Threads with an open PR are never closed here;
     * the PR merge or close closes them.
     */
    public function handle(ThreadCloser $closer): int
    {
        $idle = Thread::withoutGlobalScopes()
            ->whereRaw('coalesce(last_activity_at, created_at) < ?', [now()->subDays((int) config('yak.threads.destroy_days'))])
            ->whereDoesntHave('tasks', fn (Builder $tasks) => $tasks
                ->whereIn('status', TaskStatus::activeValues())
                ->orWhere(fn (Builder $open) => $open->whereNotNull('pr_url')->whereNull('pr_merged_at')->whereNull('pr_closed_at')));

        $cutoff = now()->subHours((int) config('yak.threads.terminal_close_hours'));
        $terminal = Thread::withoutGlobalScopes()
            ->whereHas('tasks')
            ->whereDoesntHave('tasks', fn (Builder $tasks) => $tasks
                ->whereIn('status', TaskStatus::activeValues())
                ->orWhereNotNull('pr_url')
                ->orWhereNotNull('branch_name')
                ->orWhereNull('completed_at')
                ->orWhere('completed_at', '>=', $cutoff));

        $closed = $this->closeAll($closer, $idle, 'idle') + $this->closeAll($closer, $terminal, 'terminal');

        $this->components->info("Closed {$closed} thread(s).");

        return self::SUCCESS;
    }

    /**
     * @param  Builder<Thread>  $query
     */
    private function closeAll(ThreadCloser $closer, Builder $query, string $reason): int
    {
        $closed = 0;

        $query->where('status', ThreadStatus::Open)
            ->where('owner_side', '!=', ThreadOwnerSide::Device)
            ->chunkById(100, function ($threads) use ($closer, $reason, &$closed): void {
                foreach ($threads as $thread) {
                    $closer->close($thread, $reason);
                    $closed++;
                }
            });

        return $closed;
    }
}
