<?php

namespace App\Http\Resources;

use App\Enums\RestoreStepStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\RunawayGuard;
use Illuminate\Support\Str;

/**
 * The one notice shown above the composer: a block on the thread's account or
 * sandbox, a guard stop, or a restore in progress. Null when nothing needs saying.
 */
final class ThreadNoticeData
{
    /**
     * @return array{kind: 'account_invalid'|'account_expired'|'usage_limit'|'waiting_for_account'|'waiting_for_sandbox'|'credential_exposure'|'restore_failed'|'guard_turns'|'guard_time'|'restoring', title: string, body: string, resetsAt: ?string, isOwnerOfAccount: bool, canContinue: bool, restoreSteps: list<array{label: string, status: 'pending'|'running'|'done'|'failed', error: ?string}>}|null
     */
    public static function for(Thread $thread, ?User $viewer = null): ?array
    {
        $account = $thread->claudeAccount;
        $accountName = $account !== null ? $account->name : 'the account';
        $isOwnerOfAccount = $viewer !== null && $account !== null && $account->owner_user_id === $viewer->id;
        $ownerName = $account?->owner?->name;
        $ownerFirstName = Str::before((string) $ownerName, ' ');

        $steps = self::restoreSteps($thread);
        $failedStep = collect($steps)->firstWhere('status', 'failed');

        if ($thread->blocked_reason !== null) {
            [$title, $body] = match ($thread->blocked_reason) {
                ThreadBlockReason::AccountInvalid, ThreadBlockReason::AccountExpired => self::accountProblem($thread->blocked_reason, $accountName, $isOwnerOfAccount, $ownerFirstName),
                ThreadBlockReason::UsageLimit => ['Usage limit reached', "{$accountName} has used its Claude limit for now. This thread continues on its own when the limit resets. Messages you send now are read after the reset."],
                ThreadBlockReason::WaitingForAccount => ['Waiting for the account', "{$accountName} is running its maximum number of turns. Yak starts this one as soon as a slot frees up. Your message is saved and nothing needs doing."],
                ThreadBlockReason::WaitingForSandbox => ['Waiting for a free sandbox', 'All ' . (int) config('yak.threads.awake_cap') . ' thread sandboxes are in use. Yak starts this one as soon as another frees up, usually within a few minutes. Your message is saved and nothing needs doing.'],
                ThreadBlockReason::CredentialExposure => ['A credential was exposed', 'A secret showed up where it should not, so Yak stopped this thread. Replace the secret and ask an admin to check the thread before it continues.'],
                ThreadBlockReason::RestoreFailed => ['The sandbox could not be restored', ($failedStep['error'] ?? null) !== null ? "Restore stopped at \"{$failedStep['label']}\": {$failedStep['error']}" : 'Restore stopped before the sandbox was ready. Try again.'],
            };

            return self::notice($thread->blocked_reason->value, $title, $body, $isOwnerOfAccount, false, $steps, $thread->blocked_reason === ThreadBlockReason::UsageLimit ? self::usageResetsAt($thread) : null);
        }

        $guardKind = self::guardKind($thread);

        if ($guardKind !== null) {
            $title = $guardKind === 'guard_turns'
                ? 'Claude stopped after ' . (int) config('yak.max_turns') . ' steps'
                : 'Claude stopped after ' . (int) config('yak.threads.turn_wall_clock_minutes') . ' minutes';

            return self::notice($guardKind, $title, 'Yak stops a turn that runs this long, so a loop cannot use up your plan. Continue starts a new turn with fresh limits.', $isOwnerOfAccount, true, $steps);
        }

        if ($thread->sandbox_state === ThreadSandboxState::Starting && $steps !== [] && collect($steps)->contains(fn (array $step): bool => $step['status'] !== 'done')) {
            return self::notice('restoring', 'Restoring the sandbox', 'Yak is rebuilding this thread\'s sandbox. It will be ready in a moment.', $isOwnerOfAccount, false, $steps);
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function accountProblem(ThreadBlockReason $reason, string $accountName, bool $isOwnerOfAccount, string $ownerFirstName): array
    {
        $verb = $reason === ThreadBlockReason::AccountExpired ? 'has expired' : 'was rejected by Claude';

        if ($isOwnerOfAccount) {
            return [
                $reason === ThreadBlockReason::AccountExpired ? 'Your token has expired' : 'Your token stopped working',
                "{$accountName} {$verb}, so Yak stopped this thread. Replace the secret and the thread picks up where it left off. Yak never switches to another account on its own.",
            ];
        }

        $owner = $ownerFirstName !== '' ? $ownerFirstName : 'the account owner';

        return [
            "{$owner}'s token " . ($reason === ThreadBlockReason::AccountExpired ? 'has expired' : 'stopped working'),
            "{$accountName}, the account this thread runs on, {$verb}. Only {$owner} can replace it, and Yak already told them. This thread continues once they do. To keep going now, start a new thread on your own account.",
        ];
    }

    /**
     * @param  list<array{label: string, status: 'pending'|'running'|'done'|'failed', error: ?string}>  $steps
     * @return array{kind: 'account_invalid'|'account_expired'|'usage_limit'|'waiting_for_account'|'waiting_for_sandbox'|'credential_exposure'|'restore_failed'|'guard_turns'|'guard_time'|'restoring', title: string, body: string, resetsAt: ?string, isOwnerOfAccount: bool, canContinue: bool, restoreSteps: list<array{label: string, status: 'pending'|'running'|'done'|'failed', error: ?string}>}
     */
    private static function notice(string $kind, string $title, string $body, bool $isOwnerOfAccount, bool $canContinue, array $steps, ?string $resetsAt = null): array
    {
        /** @var 'account_invalid'|'account_expired'|'usage_limit'|'waiting_for_account'|'waiting_for_sandbox'|'credential_exposure'|'restore_failed'|'guard_turns'|'guard_time'|'restoring' $kind */
        return [
            'kind' => $kind,
            'title' => $title,
            'body' => $body,
            'resetsAt' => $resetsAt,
            'isOwnerOfAccount' => $isOwnerOfAccount,
            'canContinue' => $canContinue,
            'restoreSteps' => in_array($kind, ['restoring', 'restore_failed'], true) ? $steps : [],
        ];
    }

    /**
     * @return list<array{label: string, status: 'pending'|'running'|'done'|'failed', error: ?string}>
     */
    private static function restoreSteps(Thread $thread): array
    {
        $steps = [];

        foreach ($thread->restoreSteps as $step) {
            $steps[] = [
                'label' => Str::ucfirst(str_replace('_', ' ', $step->step->value)),
                'status' => match ($step->status) {
                    RestoreStepStatus::Pending => 'pending',
                    RestoreStepStatus::Running => 'running',
                    RestoreStepStatus::Succeeded, RestoreStepStatus::Skipped => 'done',
                    RestoreStepStatus::Failed => 'failed',
                },
                'error' => $step->error,
            ];
        }

        return $steps;
    }

    /**
     * Turns versus time, read from the log row the driver writes when the guard stops a turn.
     *
     * @return 'guard_turns'|'guard_time'|null
     */
    private static function guardKind(Thread $thread): ?string
    {
        $run = TaskRun::query()->where('thread_id', $thread->id)->latest('id')->first();

        if ($run === null || $run->turn_state !== TurnState::StoppedByGuard) {
            return null;
        }

        $log = TaskLog::query()
            ->where('yak_task_id', $run->yak_task_id)
            ->where('message', 'turn_stopped_by_guard')
            ->where('created_at', '>=', $run->started_at)
            ->latest('id')
            ->first();

        return ($log?->metadata['reason'] ?? null) === RunawayGuard::WALL_CLOCK ? 'guard_time' : 'guard_turns';
    }

    private static function usageResetsAt(Thread $thread): ?string
    {
        $stored = TaskRun::query()->where('thread_id', $thread->id)->latest('id')->value('tracker_state');
        $state = is_string($stored) ? json_decode($stored, true) : null;

        $resetsAt = is_array($state) ? ($state['failure']['resetsAt'] ?? $state['rate_limit_resets_at'] ?? null) : null;

        return is_string($resetsAt) ? $resetsAt : null;
    }
}
