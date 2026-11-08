<?php

namespace App\Http\Resources;

use App\Enums\ThreadLockHolder;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Models\User;
use App\Models\YakTask;
use App\Services\Threads\RunawayGuard;

/**
 * Props for the thread panel on the task page: the sandbox state, the thread's
 * account and model, the accounts the viewer may switch to, and why the thread
 * is blocked or stopped.
 */
final class ThreadPanelData
{
    private const array BLOCK_MESSAGES = [
        'no_valid_account' => 'No Claude account can run this thread. Switch the account, or add one in Settings.',
        'invalid_token' => "This account's token was rejected. Paste a new token in Settings, or switch the account.",
        'usage_limit' => 'This account hit its usage limit. Switch the account, or wait for the limit to reset.',
        'waiting_for_sandbox' => 'All sandboxes are in use. Yak continues when one frees up, so there is nothing to do.',
    ];

    /**
     * @return array<string, mixed>|null
     */
    public static function forTask(YakTask $task, ?User $viewer): ?array
    {
        $thread = $task->thread_id === null ? null : Thread::query()->find($task->thread_id);

        if ($thread === null) {
            return null;
        }

        $account = $thread->claude_account_id === null ? null : ClaudeAccount::query()->find($thread->claude_account_id);
        $guardStop = self::guardStop($thread);
        $blockedReason = $thread->blocked_reason;

        return [
            'sandboxState' => self::sandboxState($thread->sandbox_state),
            'account' => $account === null ? null : ['id' => $account->id, 'name' => $account->name, 'isSystem' => $account->isSystem()],
            'model' => (string) ($thread->model ?? $thread->runner_model ?? ''),
            'switchableAccounts' => self::switchableAccounts($thread, $viewer),
            'models' => array_values(config('yak.threads.models')),
            'blocked' => $blockedReason === null ? null : [
                'reason' => $blockedReason->value,
                'message' => self::BLOCK_MESSAGES[$blockedReason->value],
            ],
            'guardStop' => $guardStop,
            'canContinue' => $guardStop !== null
                && $blockedReason === null
                && $thread->lock_holder === ThreadLockHolder::None
                && $thread->isOpen(),
        ];
    }

    private static function sandboxState(ThreadSandboxState $state): string
    {
        return match ($state) {
            ThreadSandboxState::Starting, ThreadSandboxState::Running => 'running',
            ThreadSandboxState::Paused => 'paused',
            ThreadSandboxState::Stopped => 'stopped',
            default => 'none',
        };
    }

    /**
     * Only people with a task in the thread may switch it (the switch route
     * enforces the same), and only to a usable account of their own or a system one.
     *
     * @return list<array{id: int, name: string, isSystem: bool}>
     */
    private static function switchableAccounts(Thread $thread, ?User $viewer): array
    {
        if ($viewer === null || ! $thread->tasks()->where('started_by_user_id', $viewer->id)->exists()) {
            return [];
        }

        $accounts = ClaudeAccount::query()
            ->where(fn ($query) => $query->whereNull('owner_user_id')->orWhere('owner_user_id', $viewer->id))
            ->when($thread->claude_account_id !== null, fn ($query) => $query->whereKeyNot($thread->claude_account_id))
            ->orderBy('name')
            ->get()
            ->filter(fn (ClaudeAccount $account): bool => $account->isUsable())
            ->map(fn (ClaudeAccount $account): array => ['id' => $account->id, 'name' => $account->name, 'isSystem' => $account->isSystem()])
            ->all();

        return array_values($accounts);
    }

    /**
     * @return array{reason: string, limit: int}|null
     */
    private static function guardStop(Thread $thread): ?array
    {
        $last = $thread->turns()->latest('id')->first();

        if ($last === null || $last->turn_state !== TurnState::StoppedByGuard) {
            return null;
        }

        $reason = (string) $last->guard_reason;

        return [
            'reason' => $reason,
            'limit' => $reason === RunawayGuard::WALL_CLOCK
                ? (int) config('yak.threads.turn_wall_clock_minutes')
                : (int) config('yak.max_turns'),
        ];
    }
}
