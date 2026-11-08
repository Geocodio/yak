<?php

namespace App\Services\HealthCheck;

use App\Models\ClaudeAccount;

/**
 * Verifies a system Claude account is usable. Unattended work (PR reviews,
 * repo setup, tasks nobody started) runs on a system account, so without one
 * those jobs have no credential. Per-account validation keeps each account's
 * status current; this check only reads it.
 */
class SystemAccountCheck implements HealthCheck
{
    public function id(): string
    {
        return 'system-account';
    }

    public function name(): string
    {
        return 'Claude System Account';
    }

    public function section(): HealthSection
    {
        return HealthSection::System;
    }

    public function run(): HealthResult
    {
        $accounts = ClaudeAccount::query()->whereNull('owner_user_id')->get();

        if ($accounts->isEmpty()) {
            return HealthResult::error(
                'No system account — add one (a setup-token) so unattended work has a credential',
                new HealthAction('Add an account', route('settings.claude-accounts')),
            );
        }

        $usable = $accounts->first(fn (ClaudeAccount $account): bool => $account->isUsable());

        if ($usable === null) {
            return HealthResult::error(
                'Every system account is invalid or expired — paste a new token',
                new HealthAction('Fix accounts', route('settings.claude-accounts')),
            );
        }

        return HealthResult::ok("Usable, {$usable->name}");
    }
}
