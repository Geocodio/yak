<?php

namespace App\Services\ClaudeAccounts;

use App\DataTransferObjects\ResolvedAccount;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\UserRepositorySetting;
use App\Models\YakTask;

class ClaudeAccountResolver
{
    public function forNewThread(YakTask $task): ResolvedAccount
    {
        $starterId = $task->started_by_user_id;
        $setting = $starterId === null
            ? null
            : UserRepositorySetting::query()->where('user_id', $starterId)->where('repo', $task->repo)->first();

        $account = $this->requestedAccount($task)
            ?? $this->usable($setting?->claudeAccount)
            ?? $this->starterDefault($starterId)
            ?? $this->systemAccountFor($task->repo);

        $model = $task->requested_model
            ?: $setting?->model
            ?: $account?->default_model
            ?: (string) config('yak.default_model');

        return new ResolvedAccount($account, $model);
    }

    public function systemAccountFor(string $repoSlug): ?ClaudeAccount
    {
        $unattended = Repository::query()->where('slug', $repoSlug)->first()?->unattendedClaudeAccount;

        return $this->usable($unattended) ?? $this->usable(ClaudeAccount::globalSystemAccount());
    }

    /**
     * The picker's choice counts only when it is the starter's own account or a system account.
     */
    private function requestedAccount(YakTask $task): ?ClaudeAccount
    {
        if ($task->requested_claude_account_id === null) {
            return null;
        }

        $account = ClaudeAccount::query()->find($task->requested_claude_account_id);
        $allowed = $account !== null
            && ($account->isSystem() || $account->owner_user_id === $task->started_by_user_id);

        return $allowed ? $this->usable($account) : null;
    }

    private function starterDefault(?int $starterId): ?ClaudeAccount
    {
        if ($starterId === null) {
            return null;
        }

        return $this->usable(
            ClaudeAccount::query()->where('owner_user_id', $starterId)->where('is_default', true)->first(),
        );
    }

    private function usable(?ClaudeAccount $account): ?ClaudeAccount
    {
        return $account?->isUsable() ? $account : null;
    }
}
