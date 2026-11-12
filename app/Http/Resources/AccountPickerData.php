<?php

namespace App\Http\Resources;

use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\User;
use App\Models\UserRepositorySetting;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;

/**
 * What an account picker needs: the accounts a person may start a thread on
 * (their own plus Yak accounts, checked ones only), and per repository the
 * account and model Yak would pick for them without a choice, plus the
 * person's configured repository default when that account is unusable.
 */
final class AccountPickerData
{
    /**
     * @return array{
     *     accounts: list<array{id: int, name: string, kind: string, icon: string, color: string, isSystem: bool, isDefault: bool, defaultModel: ?string, usable: bool, status: string}>,
     *     defaultsByRepo: array<string, array{accountId: int|null, brokenDefaultAccountId: int|null, model: string|null}>,
     *     models: list<array{family: string, latest: array{value: string, label: string, description: string}, older: list<array{value: string, label: string}>}>,
     * }
     */
    public static function forUser(?User $user): array
    {
        $accounts = array_values(ClaudeAccount::query()
            ->checked()
            ->where(fn ($query) => $query->whereNull('owner_user_id')->when($user !== null, fn ($inner) => $inner->orWhere('owner_user_id', $user?->id)))
            ->orderBy('name')
            ->get()
            ->map(fn (ClaudeAccount $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'kind' => $account->kind->value,
                'icon' => $account->icon->value,
                'color' => $account->color->value,
                'isSystem' => $account->isSystem(),
                'isDefault' => $account->is_default,
                'defaultModel' => $account->default_model,
                'usable' => $account->isUsable(),
                'status' => $account->status->value,
            ])
            ->all());

        $resolver = app(ClaudeAccountResolver::class);
        $configuredDefaults = $user === null ? collect() : UserRepositorySetting::query()->where('user_id', $user->id)->whereNotNull('claude_account_id')->pluck('claude_account_id', 'repo');

        $defaultsByRepo = Repository::query()->where('is_active', true)->orderBy('slug')->get()
            ->mapWithKeys(function (Repository $repository) use ($resolver, $user, $configuredDefaults): array {
                $resolved = $resolver->forNewThread($user, $repository->slug, null, null);
                $configured = $configuredDefaults->has($repository->slug) ? ClaudeAccount::query()->whereKey((int) $configuredDefaults[$repository->slug])->first() : null;
                $isBroken = $configured !== null && ! $configured->isUsable() && ($configured->isSystem() || $configured->owner_user_id === $user?->id);

                return [$repository->slug => ['accountId' => $resolved->account?->id, 'brokenDefaultAccountId' => $isBroken ? $configured->id : null, 'model' => $resolved->model]];
            })
            ->all();

        return ['accounts' => $accounts, 'defaultsByRepo' => $defaultsByRepo, 'models' => app(ClaudeModelCatalog::class)->families()];
    }
}
