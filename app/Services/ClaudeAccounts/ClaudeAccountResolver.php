<?php

namespace App\Services\ClaudeAccounts;

use App\DataTransferObjects\ResolvedAccount;
use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\User;
use App\Models\UserRepositorySetting;

class ClaudeAccountResolver
{
    /**
     * Pick the account and model for a new thread. Order: the chosen account
     * (the starter's own or a system account), the starter's repository
     * setting, the starter's default, the repository's unattended account,
     * the global system account. Unusable candidates are skipped, and
     * another person's account is never used.
     */
    public function forNewThread(?User $starter, string $repo, ?int $chosenAccountId, ?string $chosenModel): ResolvedAccount
    {
        $setting = $starter === null ? null : UserRepositorySetting::query()
            ->where('user_id', $starter->id)->where('repo', $repo)->first();

        $account = null;

        if ($chosenAccountId !== null) {
            $candidate = ClaudeAccount::query()->find($chosenAccountId);
            if ($candidate !== null && $candidate->isUsable() && ($candidate->isSystem() || $candidate->owner_user_id === $starter?->id)) {
                $account = $candidate;
            }
        }

        if ($account === null && $setting?->claude_account_id !== null) {
            $candidate = $this->usableOwnedBy($starter, $setting->claude_account_id);
            if ($candidate !== null) {
                $account = $candidate;
            }
        }

        if ($account === null && $starter !== null) {
            $default = ClaudeAccount::query()->where('owner_user_id', $starter->id)->where('is_default', true)->first();
            $account = $default?->isUsable() ? $default : null;
        }

        if ($account === null) {
            $account = $this->systemAccountFor($repo);
        }

        $accountModel = $account === null ? null : $account->default_model;
        $settingModel = $account !== null && $account->id === $setting?->claude_account_id ? $setting->model : null;
        $model = $chosenModel ?? $settingModel ?? $accountModel ?? (string) config('yak.default_model');

        return new ResolvedAccount($account, $model);
    }

    /**
     * The repository's unattended account, else the global system account.
     */
    public function systemAccountFor(string $repo): ?ClaudeAccount
    {
        $unattended = Repository::query()->where('slug', $repo)->first()?->unattendedClaudeAccount;
        if ($unattended?->isUsable()) {
            return $unattended;
        }

        $global = ClaudeAccount::globalSystemAccount();

        return $global?->isUsable() ? $global : null;
    }

    private function usableOwnedBy(?User $starter, int $accountId): ?ClaudeAccount
    {
        $candidate = ClaudeAccount::query()->find($accountId);

        return $candidate !== null && $candidate->isUsable() && ($candidate->isSystem() || $candidate->owner_user_id === $starter?->id)
            ? $candidate : null;
    }
}
