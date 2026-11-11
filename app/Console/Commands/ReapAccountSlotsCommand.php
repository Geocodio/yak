<?php

namespace App\Console\Commands;

use App\Enums\RunnerState;
use App\Enums\TurnState;
use App\Models\TaskRun;
use App\Services\ClaudeAccounts\AccountConcurrency;
use App\Services\Threads\AuthProxyPlaceholders;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('yak:reap-account-slots')]
#[Description('Free account slots and auth proxy placeholders leaked by workers that died mid-run')]
class ReapAccountSlotsCommand extends Command
{
    private const MINIMUM_HELD_MINUTES = 2;

    private const HEARTBEAT_GRACE_MINUTES = 3;

    public function handle(AccountConcurrency $concurrency, AuthProxyPlaceholders $placeholders): int
    {
        $reaped = 0;

        TaskRun::query()
            ->whereNotNull('account_slot_held_at')
            ->where('account_slot_held_at', '<=', now()->subMinutes(self::MINIMUM_HELD_MINUTES))
            ->with('thread')
            ->chunkById(100, function (Collection $runs) use ($concurrency, $placeholders, &$reaped): void {
                foreach ($runs as $run) {
                    if (! $this->isDead($run)) {
                        continue;
                    }

                    $concurrency->releaseRun($run);

                    if ($run->thread_id === null) {
                        $placeholders->revoke('run:' . $run->id);
                    } elseif ($run->thread !== null && $this->runnerIsGone($run)) {
                        $placeholders->revoke($run->thread);
                    }

                    $reaped++;
                }
            });

        $this->info("Reaped {$reaped} account slot(s).");

        return self::SUCCESS;
    }

    private function isDead(TaskRun $run): bool
    {
        if ($run->thread_id === null) {
            $lastAlive = $run->oneshot_heartbeat_at ?? $run->account_slot_held_at;

            return $run->finished_at !== null
                || ($run->oneshot_deadline_at !== null && $run->oneshot_deadline_at->isPast())
                || ($lastAlive !== null && $lastAlive->lt(now()->subMinutes(self::HEARTBEAT_GRACE_MINUTES)));
        }

        // A turn waiting for a sandbox holds its slot with no runner while the sandbox wakes.
        return in_array($run->turn_state, [TurnState::Completed, TurnState::Failed, TurnState::StoppedByGuard, TurnState::Interrupted], true)
            || $this->runnerIsGone($run);
    }

    private function runnerIsGone(TaskRun $run): bool
    {
        $thread = $run->thread;

        if ($thread === null || ! $thread->isOpen()) {
            return true;
        }

        return $run->turn_state === TurnState::Running && $thread->runner_state === RunnerState::None;
    }
}
