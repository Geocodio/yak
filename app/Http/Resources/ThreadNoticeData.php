<?php

namespace App\Http\Resources;

use App\Enums\EvictionReason;
use App\Enums\RestoreStepName;
use App\Enums\RestoreStepStatus;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadSandboxState;
use App\Enums\TurnState;
use App\Models\Repository;
use App\Models\TaskLog;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\User;
use App\Services\ClaudeAccounts\AccountConcurrency;
use App\Services\Threads\RunawayGuard;
use App\Services\Threads\SandboxCapacity;
use App\Support\SecretRedactor;
use Illuminate\Support\Str;

/**
 * The one notice shown above the composer: a block on the thread's account or
 * sandbox, a guard stop, or a restore in progress. Null when nothing needs saying.
 */
final class ThreadNoticeData
{
    /**
     * @return array{kind: 'account_invalid'|'account_expired'|'usage_limit'|'waiting_for_account'|'waiting_for_sandbox'|'credential_exposure'|'restore_failed'|'guard_turns'|'guard_time'|'restoring', title: string, body: string, resetsAt: ?string, isOwnerOfAccount: bool, canContinue: bool, accountSettingsUrl: ?string, startOnOwnAccountUrl: ?string, restoreSteps: list<array{key: string, label: string, detail: string, status: 'pending'|'running'|'done'|'failed', error: ?string, durationSeconds: ?float, outputTail: list<string>, exitCode: ?int}>, waiting: ?array{position: ?int, turnsRunning: int, turnsLimit: int, accountName: string, slots: list<array{state: 'running'|'paused', idleMinutes: ?int, isMine: bool, isThis: bool}>, ownSlotsUsed: ?int, ownSlotsCap: ?int, stoppableThreadId: ?int}}|null
     */
    public static function for(Thread $thread, ?User $viewer = null): ?array
    {
        $account = $thread->claudeAccount;
        $accountName = $account !== null ? $account->name : 'the account';
        $isOwnerOfAccount = $viewer !== null && $account !== null && $account->owner_user_id === $viewer->id;
        $ownerName = $account?->owner?->name;
        $ownerFirstName = Str::before((string) $ownerName, ' ');

        $steps = self::restoreSteps($thread);
        $failedIndex = array_search('failed', array_column($steps, 'status'), true);

        if ($thread->blocked_reason !== null) {
            [$title, $body] = match ($thread->blocked_reason) {
                ThreadBlockReason::AccountInvalid, ThreadBlockReason::AccountExpired => self::accountProblem($thread->blocked_reason, $accountName, $isOwnerOfAccount, $ownerFirstName),
                ThreadBlockReason::UsageLimit => ['Usage limit reached', "{$accountName} has used its Claude limit for now. Messages you send now start when the limit resets."],
                ThreadBlockReason::WaitingForAccount => ['Waiting for the account', "{$accountName} is running its maximum number of turns. " . self::queuePositionSentence($thread) . 'Yak starts this one as soon as a slot frees up. Your message is saved and nothing needs doing.'],
                ThreadBlockReason::WaitingForSandbox => ['Waiting for a free sandbox', 'All ' . (int) config('yak.threads.awake_cap') . ' thread sandboxes are in use. Yak stops a paused one after 5 idle minutes and starts this one soon. Your message is saved and nothing needs doing.'],
                ThreadBlockReason::CredentialExposure => ['A credential was exposed', 'A secret showed up where it should not, so Yak stopped this thread. Replace the secret and ask an admin to check the thread before it continues.'],
                ThreadBlockReason::RestoreFailed => ['Rebuilding the sandbox stopped' . ($failedIndex !== false ? ' at step ' . ($failedIndex + 1) : ''), 'Your message is saved. Claude reads it once the sandbox is back.'],
            };

            return self::notice($thread->blocked_reason->value, $title, $body, $isOwnerOfAccount, false, $steps, $thread->blocked_reason === ThreadBlockReason::UsageLimit ? self::usageResetsAt($thread) : null, self::waiting($thread, $viewer, $accountName));
        }

        $guardKind = self::guardKind($thread);

        if ($guardKind !== null) {
            $title = $guardKind === 'guard_turns'
                ? 'Claude stopped after ' . (int) config('yak.max_turns') . ' steps'
                : 'Claude stopped after ' . (int) config('yak.threads.turn_wall_clock_minutes') . ' minutes';

            return self::notice($guardKind, $title, 'Yak stops a turn that runs this long, so a loop cannot use up your plan. Continue starts a new turn with fresh limits.', $isOwnerOfAccount, true, $steps);
        }

        if ($thread->sandbox_state === ThreadSandboxState::Starting && $steps !== [] && collect($steps)->contains(fn (array $step): bool => $step['status'] !== 'done')) {
            return self::notice('restoring', 'Rebuilding the sandbox', 'The old sandbox was removed to free disk. Your branch, changes and conversation are safe, and your message is saved.', $isOwnerOfAccount, false, $steps);
        }

        return null;
    }

    private static function queuePositionSentence(Thread $thread): string
    {
        $run = TaskRun::query()
            ->where('thread_id', $thread->id)
            ->where('turn_state', TurnState::WaitingForAccount)
            ->latest('id')
            ->first();

        $position = $run !== null ? app(AccountConcurrency::class)->position($run) : 0;

        return $position > 0 ? "You are number {$position} in line. " : '';
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
     * @param  list<array{key: string, label: string, detail: string, status: 'pending'|'running'|'done'|'failed', error: ?string, durationSeconds: ?float, outputTail: list<string>, exitCode: ?int}>  $steps
     * @param  array{position: ?int, turnsRunning: int, turnsLimit: int, accountName: string, slots: list<array{state: 'running'|'paused', idleMinutes: ?int, isMine: bool, isThis: bool}>, ownSlotsUsed: ?int, ownSlotsCap: ?int, stoppableThreadId: ?int}|null  $waiting
     * @return array{kind: 'account_invalid'|'account_expired'|'usage_limit'|'waiting_for_account'|'waiting_for_sandbox'|'credential_exposure'|'restore_failed'|'guard_turns'|'guard_time'|'restoring', title: string, body: string, resetsAt: ?string, isOwnerOfAccount: bool, canContinue: bool, accountSettingsUrl: ?string, startOnOwnAccountUrl: ?string, restoreSteps: list<array{key: string, label: string, detail: string, status: 'pending'|'running'|'done'|'failed', error: ?string, durationSeconds: ?float, outputTail: list<string>, exitCode: ?int}>, waiting: ?array{position: ?int, turnsRunning: int, turnsLimit: int, accountName: string, slots: list<array{state: 'running'|'paused', idleMinutes: ?int, isMine: bool, isThis: bool}>, ownSlotsUsed: ?int, ownSlotsCap: ?int, stoppableThreadId: ?int}}
     */
    private static function notice(string $kind, string $title, string $body, bool $isOwnerOfAccount, bool $canContinue, array $steps, ?string $resetsAt = null, ?array $waiting = null): array
    {
        $isAccountProblem = in_array($kind, ['account_invalid', 'account_expired', 'credential_exposure'], true);

        /** @var 'account_invalid'|'account_expired'|'usage_limit'|'waiting_for_account'|'waiting_for_sandbox'|'credential_exposure'|'restore_failed'|'guard_turns'|'guard_time'|'restoring' $kind */
        return [
            'kind' => $kind,
            'title' => $title,
            'body' => $body,
            'resetsAt' => $resetsAt,
            'isOwnerOfAccount' => $isOwnerOfAccount,
            'canContinue' => $canContinue,
            'accountSettingsUrl' => $isAccountProblem && $isOwnerOfAccount ? route('settings.claude-accounts') : null,
            'startOnOwnAccountUrl' => ($isAccountProblem || $kind === 'usage_limit') ? route('threads.new') : null,
            'restoreSteps' => in_array($kind, ['restoring', 'restore_failed'], true) ? $steps : [],
            'waiting' => $waiting,
        ];
    }

    /**
     * @return list<array{key: string, label: string, detail: string, status: 'pending'|'running'|'done'|'failed', error: ?string, durationSeconds: ?float, outputTail: list<string>, exitCode: ?int}>
     */
    private static function restoreSteps(Thread $thread): array
    {
        $steps = [];
        $slugs = [$thread->repo, ...$thread->repositories->pluck('repo')->all()];
        $secrets = [];

        foreach (Repository::query()->whereIn('slug', $slugs)->get() as $repository) {
            array_push($secrets, ...array_map('strval', array_values($repository->build_credentials ?? [])));
        }

        foreach ($thread->restoreSteps as $step) {
            if ($step->step === RestoreStepName::SetUpExtraRepositories && $step->status === RestoreStepStatus::Skipped) {
                continue;
            }

            $copy = self::stepCopy($step->step);
            $error = $step->error === null ? null : SecretRedactor::redactExact($step->error, $secrets);
            $output = $step->output === null ? null : SecretRedactor::redactExact($step->output, $secrets);
            $lines = $output === null ? [] : array_values(array_filter(preg_split('/\R/', rtrim($output)) ?: [], fn (string $line): bool => $line !== ''));

            $steps[] = [
                'key' => $step->step->value,
                'label' => $copy['label'],
                'detail' => $copy['detail'],
                'status' => match ($step->status) {
                    RestoreStepStatus::Pending => 'pending',
                    RestoreStepStatus::Running => 'running',
                    RestoreStepStatus::Succeeded, RestoreStepStatus::Skipped => 'done',
                    RestoreStepStatus::Failed => 'failed',
                },
                'error' => $error,
                'durationSeconds' => $step->started_at !== null && $step->finished_at !== null ? (float) $step->started_at->diffInSeconds($step->finished_at, true) : null,
                'outputTail' => array_slice($lines, -5),
                'exitCode' => $error !== null && preg_match('/exit code (\d+)/i', $error, $match) === 1 ? (int) $match[1] : null,
            ];
        }

        return $steps;
    }

    /**
     * @return array{label: string, detail: string}
     */
    private static function stepCopy(RestoreStepName $step): array
    {
        return match ($step) {
            RestoreStepName::CreateSandbox => ['label' => 'Clone the repo template', 'detail' => 'Starts a fresh sandbox from the repository template.'],
            RestoreStepName::CheckoutBranch => ['label' => 'Check out the thread branch', 'detail' => 'Puts your branch and saved changes back.'],
            RestoreStepName::RunSetupScript => ['label' => 'Run .yak/setup.sh', 'detail' => 'Installs what the repository needs to run.'],
            RestoreStepName::RunRefreshTriggers => ['label' => 'Run the refresh triggers', 'detail' => 'Refreshes dependencies when their lock files changed.'],
            RestoreStepName::SetUpExtraRepositories => ['label' => 'Set up the extra repositories', 'detail' => 'Clones the other repositories of this thread.'],
            RestoreStepName::RestoreSessionFiles => ['label' => 'Restore the conversation', 'detail' => 'Gives Claude its saved session back.'],
            RestoreStepName::PrepareEnvironment => ['label' => 'Start Claude', 'detail' => 'Starts the runner so your message can be read.'],
        };
    }

    /**
     * Queue position and capacity behind a waiting notice; null for every other notice.
     *
     * @return array{position: ?int, turnsRunning: int, turnsLimit: int, accountName: string, slots: list<array{state: 'running'|'paused', idleMinutes: ?int, isMine: bool, isThis: bool}>, ownSlotsUsed: ?int, ownSlotsCap: ?int, stoppableThreadId: ?int}|null
     */
    private static function waiting(Thread $thread, ?User $viewer, string $accountName): ?array
    {
        $account = $thread->claudeAccount;
        $concurrency = app(AccountConcurrency::class);
        $turnsRunning = $account !== null ? $concurrency->heldCount($account) : 0;
        $turnsLimit = $account !== null ? $account->max_concurrent_turns : 0;

        if ($thread->blocked_reason === ThreadBlockReason::WaitingForAccount) {
            $run = TaskRun::query()->where('thread_id', $thread->id)->where('turn_state', TurnState::WaitingForAccount)->latest('id')->first();
            $position = $run !== null ? $concurrency->position($run) : 0;

            return self::waitingProps($position > 0 ? $position : null, $turnsRunning, $turnsLimit, $accountName, [], null, null, null);
        }

        if ($thread->blocked_reason !== ThreadBlockReason::WaitingForSandbox) {
            return null;
        }

        $capacity = app(SandboxCapacity::class);
        $slots = [];

        foreach ($capacity->awakeSlots() as $slot) {
            $paused = $slot->sandbox_state === ThreadSandboxState::Paused;
            $slots[] = [
                'state' => $paused ? 'paused' : 'running',
                'idleMinutes' => $paused && $slot->last_activity_at !== null ? (int) $slot->last_activity_at->diffInMinutes(now(), true) : null,
                'isMine' => $viewer !== null && (int) $slot->getAttribute('effective_owner_id') === $viewer->id,
                'isThis' => $slot->id === $thread->id,
            ];
        }

        $position = $thread->blocked_at === null ? null : Thread::query()->withoutGlobalScopes()
            ->where('blocked_reason', ThreadBlockReason::WaitingForSandbox->value)
            ->where('blocked_at', '<=', $thread->blocked_at)
            ->count();

        $ownerId = $thread->effectiveOwnerUserId();
        $ownCap = (int) config('yak.threads.person_awake_cap');
        $ownUsed = $ownerId !== null ? $capacity->awakeCountFor($ownerId) : null;
        $isShareFull = $ownUsed !== null && $ownCap > 0 && $ownUsed >= $ownCap;
        $stoppable = $isShareFull && $viewer !== null && $ownerId === $viewer->id ? $capacity->evictionCandidate($thread, EvictionReason::PersonShare) : null;

        return self::waitingProps($position, $turnsRunning, $turnsLimit, $accountName, $slots, $isShareFull ? $ownUsed : null, $isShareFull ? $ownCap : null, $stoppable?->id);
    }

    /**
     * @param  list<array{state: 'running'|'paused', idleMinutes: ?int, isMine: bool, isThis: bool}>  $slots
     * @return array{position: ?int, turnsRunning: int, turnsLimit: int, accountName: string, slots: list<array{state: 'running'|'paused', idleMinutes: ?int, isMine: bool, isThis: bool}>, ownSlotsUsed: ?int, ownSlotsCap: ?int, stoppableThreadId: ?int}
     */
    private static function waitingProps(?int $position, int $turnsRunning, int $turnsLimit, string $accountName, array $slots, ?int $ownSlotsUsed, ?int $ownSlotsCap, ?int $stoppableThreadId): array
    {
        return [
            'position' => $position,
            'turnsRunning' => $turnsRunning,
            'turnsLimit' => $turnsLimit,
            'accountName' => $accountName,
            'slots' => $slots,
            'ownSlotsUsed' => $ownSlotsUsed,
            'ownSlotsCap' => $ownSlotsCap,
            'stoppableThreadId' => $stoppableThreadId,
        ];
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
        $accountResetsAt = $thread->claudeAccount?->usage_limit_resets_at;

        if ($accountResetsAt !== null) {
            return $accountResetsAt->toIso8601String();
        }

        $stored = TaskRun::query()->where('thread_id', $thread->id)->latest('id')->value('tracker_state');
        $state = is_string($stored) ? json_decode($stored, true) : null;

        $resetsAt = is_array($state) ? ($state['failure']['resetsAt'] ?? $state['rate_limit_resets_at'] ?? null) : null;

        return is_string($resetsAt) ? $resetsAt : null;
    }
}
