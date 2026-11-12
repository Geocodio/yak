<?php

namespace App\Http\Resources;

use App\Models\ClaudeAccount;
use App\Models\Device;
use App\Models\User;

/**
 * What is left to set up for one person: drives the setup bar and the Teleport
 * install steps.
 */
final class SetupData
{
    /**
     * @return array{
     *     hasOwnAccount: bool,
     *     brokenAccount: array{id: int, name: string}|null,
     *     hasDevice: bool,
     *     latestDeviceName: string|null,
     *     needsYakAccount: bool,
     *     installCommand: string,
     *     loginCommand: string,
     *     models: list<string>,
     *     appName: string,
     * }
     */
    public static function for(User $user): array
    {
        $ownAccounts = ClaudeAccount::query()->where('owner_user_id', $user->id)->orderBy('name')->get();
        $brokenAccount = $ownAccounts->first(fn (ClaudeAccount $account): bool => ! $account->isUsable());
        $latestDevice = Device::query()->active()->where('user_id', $user->id)->latest()->first();
        $systemAccount = ClaudeAccount::globalSystemAccount();
        $instanceUrl = rtrim((string) config('app.url'), '/');

        return [
            'hasOwnAccount' => $ownAccounts->isNotEmpty(),
            'brokenAccount' => $brokenAccount === null ? null : ['id' => $brokenAccount->id, 'name' => $brokenAccount->name],
            'hasDevice' => $latestDevice !== null,
            'latestDeviceName' => $latestDevice?->name,
            'needsYakAccount' => $user->isAdmin() && ($systemAccount === null || ! $systemAccount->isUsable()),
            'installCommand' => "curl -fsSL {$instanceUrl}/install | sh",
            'loginCommand' => "yak login --url {$instanceUrl}",
            'models' => array_values(config('claude-accounts.models')),
            'appName' => (string) config('app.name'),
        ];
    }
}
