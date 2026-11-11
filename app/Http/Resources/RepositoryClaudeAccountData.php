<?php

namespace App\Http\Resources;

use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\User;
use App\Models\UserRepositorySetting;

/**
 * The "Claude account" section of the repository page.
 */
final class RepositoryClaudeAccountData
{
    /**
     * @return array{
     *     accounts: list<array{id: int, name: string, kind: string, icon: string, color: string, isSystem: bool, isDefault: bool, defaultModel: ?string, usable: bool, status: string}>,
     *     mySetting: array{accountId: int|null, model: string|null},
     *     unattendedAccountId: int|null,
     *     globalSystemAccountId: int|null,
     *     canChangeUnattended: bool,
     *     models: list<string>,
     * }
     */
    public static function forRepository(Repository $repository, ?User $user): array
    {
        $picker = AccountPickerData::forUser($user);

        $setting = $user === null ? null : UserRepositorySetting::query()
            ->where('user_id', $user->id)->where('repo', $repository->slug)->first();

        return [
            'accounts' => $picker['accounts'],
            'mySetting' => ['accountId' => $setting?->claude_account_id, 'model' => $setting?->model],
            'unattendedAccountId' => $repository->unattended_claude_account_id,
            'globalSystemAccountId' => ClaudeAccount::globalSystemAccount()?->id,
            'canChangeUnattended' => $user?->can('useAsUnattended', ClaudeAccount::class) ?? false,
            'models' => $picker['models'],
        ];
    }
}
