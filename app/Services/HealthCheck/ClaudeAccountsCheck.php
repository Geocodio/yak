<?php

namespace App\Services\HealthCheck;

use App\Models\ClaudeAccount;

/**
 * Green when at least one Claude account can run a turn. Usable means the
 * same thing it does for the account picker and the resolver:
 * ClaudeAccount::isUsable().
 */
class ClaudeAccountsCheck implements HealthCheck
{
    public function id(): string
    {
        return 'claude-accounts';
    }

    public function name(): string
    {
        return 'Claude accounts';
    }

    public function section(): HealthSection
    {
        return HealthSection::System;
    }

    public function run(): HealthResult
    {
        $usable = ClaudeAccount::query()->get()->filter(fn (ClaudeAccount $account): bool => $account->isUsable())->count();

        if ($usable === 0) {
            return HealthResult::error('No usable Claude account. Add one or replace an expired secret under Settings, Claude accounts.');
        }

        return HealthResult::ok($usable === 1 ? '1 usable account' : "{$usable} usable accounts");
    }
}
