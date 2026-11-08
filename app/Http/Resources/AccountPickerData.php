<?php

namespace App\Http\Resources;

use App\Models\ClaudeAccount;
use App\Models\User;
use App\Models\UserRepositorySetting;

/**
 * Props for the account and model pickers: the accounts a person may use, their per-repo
 * defaults, and the selectable models.
 */
final class AccountPickerData
{
    /**
     * @return array{accounts: list<array<string, mixed>>, defaultsByRepo: array<string, array{accountId: int|null, model: string|null}>, models: list<string>}
     */
    public static function forUser(?User $user): array
    {
        $accounts = ClaudeAccount::query()
            ->where(fn ($query) => $query->whereNull('owner_user_id')->orWhere('owner_user_id', $user?->id))
            ->orderBy('name')
            ->get();

        $defaults = $user === null
            ? collect()
            : UserRepositorySetting::query()->where('user_id', $user->id)->get();

        return [
            'accounts' => array_values($accounts->map(ClaudeAccountData::from(...))->all()),
            'defaultsByRepo' => $defaults
                ->mapWithKeys(fn (UserRepositorySetting $setting): array => [
                    $setting->repo => ['accountId' => $setting->claude_account_id, 'model' => $setting->model],
                ])
                ->all(),
            'models' => array_values(config('yak.threads.models')),
        ];
    }
}
