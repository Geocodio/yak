<?php

namespace App\Observers;

use App\Enums\ThreadBlockReason;
use App\Models\Thread;
use App\Models\User;
use App\Services\ClaudeAccounts\AccountNotifier;
use App\Services\Push\AccountProblemPush;
use App\Services\Push\PushEvent;
use App\Services\Push\PushRecipients;
use App\Services\Push\ThreadPush;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pushes when a thread becomes blocked on something only a person can fix.
 * Waiting states need nothing from anyone and stay silent.
 */
class ThreadBlockedPushObserver
{
    private const int USAGE_LIMIT_EPISODE_MINUTES = 360;

    public function __construct(private readonly ThreadPush $push, private readonly PushRecipients $recipients, private readonly AccountProblemPush $accountProblems, private readonly AccountNotifier $accountNotifier) {}

    public function updated(Thread $thread): void
    {
        $reason = $thread->blocked_reason;

        if ($reason === null || ! $thread->wasChanged('blocked_reason')) {
            return;
        }

        $body = match ($reason) {
            ThreadBlockReason::UsageLimit => 'The thread hit its usage limit and continues when it resets.',
            ThreadBlockReason::CredentialExposure => 'A credential may have been exposed. Open the thread to review.',
            ThreadBlockReason::RestoreFailed => 'The thread could not be restored. Open it to retry.',
            default => null,
        };

        if ($body !== null) {
            $this->push->send(PushEvent::NeedsYou, $thread, $this->recipients->forBlockedThread($thread), $body, "thread-{$thread->id}-blocked");
        }

        if ($reason === ThreadBlockReason::UsageLimit) {
            $this->notifyUsageLimit($thread);
        }
    }

    /**
     * One push per account per episode, and one Slack and email notice per
     * account and driver per episode, however many threads it blocks.
     */
    private function notifyUsageLimit(Thread $thread): void
    {
        $account = $thread->claudeAccount;

        if ($account === null) {
            return;
        }

        $expiresAt = now()->addMinutes(self::USAGE_LIMIT_EPISODE_MINUTES);

        if (Cache::add("account-problem:{$account->id}:usage-limit", true, $expiresAt)) {
            $this->accountProblems->send($account, "{$account->name} hit its usage limit.");
        }

        $driverId = $thread->runs()->whereNotNull('driver_user_id')->latest('id')->value('driver_user_id');

        if (! Cache::add("account-problem:{$account->id}:usage-limit:" . ($driverId ?? 'none'), true, $expiresAt)) {
            return;
        }

        try {
            $this->accountNotifier->usageLimit($account, $account->usage_limit_resets_at, $driverId === null ? null : User::find($driverId));
        } catch (Throwable $exception) {
            Log::channel('yak')->warning('Claude account notice failed', ['account_id' => $account->id, 'error' => $exception->getMessage()]);
        }
    }
}
