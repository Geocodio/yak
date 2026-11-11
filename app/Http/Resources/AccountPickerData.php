<?php

namespace App\Http\Resources;

use App\Models\ClaudeAccount;
use App\Models\Repository;
use App\Models\User;
use App\Services\ClaudeAccounts\ClaudeAccountResolver;

/**
 * What an account picker needs: the accounts a person may start a thread on
 * (their own plus Yak accounts, checked ones only), and per repository the
 * account and model Yak would pick for them without a choice.
 */
final class AccountPickerData
{
    /**
     * @return array{
     *     accounts: list<array{id: int, name: string, kind: string, icon: string, color: string, isSystem: bool, isDefault: bool, defaultModel: ?string, usable: bool, status: string}>,
     *     defaultsByRepo: array<string, array{accountId: int|null, model: string|null}>,
     *     models: list<string>,
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

        $defaultsByRepo = Repository::query()->where('is_active', true)->orderBy('slug')->get()
            ->mapWithKeys(function (Repository $repository) use ($resolver, $user): array {
                $resolved = $resolver->forNewThread($user, $repository->slug, null, null);

                return [$repository->slug => ['accountId' => $resolved->account?->id, 'model' => $resolved->model]];
            })
            ->all();

        /** @var list<string> $models */
        $models = array_values(config('claude-accounts.models'));

        return ['accounts' => $accounts, 'defaultsByRepo' => $defaultsByRepo, 'models' => $models];
    }
}
