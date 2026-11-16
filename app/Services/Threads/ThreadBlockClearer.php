<?php

namespace App\Services\Threads;

use App\Enums\ThreadBlockReason;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use Illuminate\Support\Facades\DB;

/**
 * Lifts the thread blocks that an account problem caused once the problem is
 * gone: the usage limit has reset, or the account is usable again.
 */
class ThreadBlockClearer
{
    private const ACCOUNT_REASONS = [ThreadBlockReason::AccountInvalid, ThreadBlockReason::AccountExpired];

    /**
     * @return int the number of threads cleared
     */
    public function clearResolved(): int
    {
        $cleared = 0;

        ClaudeAccount::query()
            ->where('usage_limit_resets_at', '<=', now())
            ->whereIn('id', $this->blockedAccountIds([ThreadBlockReason::UsageLimit]))
            ->get()
            ->each(function (ClaudeAccount $account) use (&$cleared): void {
                $cleared += $this->clearThreads($account, [ThreadBlockReason::UsageLimit]);
                $account->forceFill(['usage_limit_resets_at' => null])->save();
            });

        ClaudeAccount::query()
            ->whereIn('id', $this->blockedAccountIds(self::ACCOUNT_REASONS))
            ->get()
            ->each(function (ClaudeAccount $account) use (&$cleared): void {
                $cleared += $account->isUsable() ? $this->clearThreads($account, self::ACCOUNT_REASONS) : 0;
            });

        return $cleared;
    }

    /**
     * Clears the invalid and expired blocks of one account's threads when the
     * account is usable again. A usage limit waits for its reset time.
     */
    public function clearForAccount(ClaudeAccount $account): int
    {
        return $account->refresh()->isUsable() ? $this->clearThreads($account, self::ACCOUNT_REASONS) : 0;
    }

    /**
     * @param  list<ThreadBlockReason>  $reasons
     * @return array<int, int>
     */
    private function blockedAccountIds(array $reasons): array
    {
        return Thread::query()->whereIn('blocked_reason', $reasons)->distinct()->pluck('claude_account_id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
    }

    /**
     * @param  list<ThreadBlockReason>  $reasons
     */
    private function clearThreads(ClaudeAccount $account, array $reasons): int
    {
        $cleared = 0;

        Thread::query()
            ->where('claude_account_id', $account->id)
            ->whereIn('blocked_reason', $reasons)
            ->pluck('id')
            ->each(function (int $threadId) use ($reasons, &$cleared): void {
                DB::transaction(function () use ($threadId, $reasons, &$cleared): void {
                    $thread = Thread::query()->lockForUpdate()->find($threadId);

                    if ($thread !== null && in_array($thread->blocked_reason, $reasons, true)) {
                        $thread->forceFill(['blocked_reason' => null, 'blocked_at' => null])->save();
                        $cleared++;
                    }
                });
            });

        return $cleared;
    }
}
