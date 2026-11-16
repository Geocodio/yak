<?php

namespace App\Services\ClaudeAccounts;

use App\Enums\ThreadBlockReason;
use App\Enums\ThreadStatus;
use App\Enums\TurnState;
use App\Events\AccountSlotFreed;
use App\Models\ClaudeAccount;
use App\Models\TaskRun;
use App\Models\Thread;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Per-account turn slots. A slot is `task_runs.account_slot_held_at`; the
 * account row lock serialises acquisition.
 */
class AccountConcurrency
{
    public function acquire(ClaudeAccount $account, TaskRun $run): bool
    {
        return DB::transaction(function () use ($account, $run): bool {
            $locked = ClaudeAccount::query()->lockForUpdate()->findOrFail($account->id);
            $fresh = TaskRun::query()->findOrFail($run->id);

            if ($fresh->account_slot_held_at !== null && $fresh->claude_account_id === $locked->id) {
                return true;
            }

            $isOlderWaiterPresent = $this->healthyWaiters($locked->id)->where('id', '<', $run->id)->exists();

            if (! $isOlderWaiterPresent && $this->heldCount($locked) < $locked->max_concurrent_turns) {
                $run->forceFill(['account_slot_held_at' => now(), 'claude_account_id' => $locked->id])->save();

                return true;
            }

            $run->forceFill(['turn_state' => TurnState::WaitingForAccount, 'claude_account_id' => $locked->id])->save();

            return false;
        });
    }

    public function heldCount(ClaudeAccount $account): int
    {
        return TaskRun::query()
            ->where('claude_account_id', $account->id)
            ->whereNotNull('account_slot_held_at')
            ->count();
    }

    public function release(ClaudeAccount $account, TaskRun $run): void
    {
        if (TaskRun::query()->whereKey($run->id)->value('account_slot_held_at') === null) {
            return;
        }

        $run->forceFill(['account_slot_held_at' => null])->save();
        AccountSlotFreed::dispatch($account->id);
    }

    /**
     * Frees the slot a run holds, resolving its account from the run itself.
     */
    public function releaseRun(TaskRun $run): void
    {
        $accountId = TaskRun::query()->whereKey($run->id)->whereNotNull('account_slot_held_at')->value('claude_account_id');

        if ($accountId === null) {
            return;
        }

        $this->release(ClaudeAccount::query()->whereKey($accountId)->firstOrFail(), $run);
    }

    public function position(TaskRun $run): int
    {
        if ($run->turn_state !== TurnState::WaitingForAccount || $run->claude_account_id === null) {
            return 0;
        }

        if (! $this->healthyWaiters($run->claude_account_id)->whereKey($run->id)->exists()) {
            return 0;
        }

        return $this->healthyWaiters($run->claude_account_id)->where('id', '<=', $run->id)->count();
    }

    /**
     * Waiting runs that can still advance: one-shot runs, or runs on an open
     * thread that is unblocked or only waiting for capacity. Waiters on closed
     * or blocked threads keep their turn but do not hold the queue.
     *
     * @return Builder<TaskRun>
     */
    public function healthyWaiters(int $claudeAccountId): Builder
    {
        return TaskRun::query()
            ->where('claude_account_id', $claudeAccountId)
            ->where('turn_state', TurnState::WaitingForAccount)
            ->where(function (Builder $query): void {
                $query->whereNull('thread_id')->orWhereHas('thread', function (Builder $thread): void {
                    $thread->withoutGlobalScopes()
                        ->where('status', ThreadStatus::Open)
                        ->where(function (Builder $reason): void {
                            $reason->whereNull('blocked_reason')->orWhereIn('blocked_reason', [
                                ThreadBlockReason::WaitingForAccount,
                                ThreadBlockReason::WaitingForSandbox,
                            ]);
                        });
                });
            });
    }
}
