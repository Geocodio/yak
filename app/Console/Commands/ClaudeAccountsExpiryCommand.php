<?php

namespace App\Console\Commands;

use App\Enums\ClaudeAccountStatus;
use App\Models\ClaudeAccount;
use App\Services\ClaudeAccounts\AccountNotifier;
use Illuminate\Console\Command;

class ClaudeAccountsExpiryCommand extends Command
{
    protected $signature = 'yak:claude-accounts-expiry';

    protected $description = 'Mark expired Claude accounts and warn owners before their token expires';

    public function handle(AccountNotifier $notifier): int
    {
        $active = fn () => ClaudeAccount::query()->where('status', ClaudeAccountStatus::Active)->whereNotNull('expires_at');

        foreach ($active()->where('expires_at', '<=', now())->get() as $account) {
            $account->markExpired();
            $notifier->expired($account);
        }

        $warnings = $active()
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays((int) config('claude-accounts.expiry_warning_days')))
            ->whereNull('last_expiry_warning_at')
            ->get();

        foreach ($warnings as $account) {
            $account->forceFill(['last_expiry_warning_at' => now()])->save();
            $notifier->expiringSoon($account);
        }

        return self::SUCCESS;
    }
}
