<?php

namespace App\Http\Resources;

use App\Models\ClaudeAccount;
use App\Models\Repository;

/**
 * The account shape the Settings > Claude accounts page renders. It never
 * carries the secret. Mirrored by resources/js/types/claude-accounts.ts.
 */
final class ClaudeAccountData
{
    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     kind: string,
     *     icon: string,
     *     color: string,
     *     isSystem: bool,
     *     isDefault: bool,
     *     defaultModel: ?string,
     *     maxConcurrentTurns: int,
     *     status: string,
     *     validatedAt: ?string,
     *     expiresAt: ?string,
     *     expiresSoon: bool,
     *     lastUsedAt: ?string,
     *     lastError: ?string,
     *     unattendedRepos: list<string>,
     * }
     */
    public static function from(ClaudeAccount $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'kind' => $account->kind->value,
            'icon' => $account->icon->value,
            'color' => $account->color->value,
            'isSystem' => $account->isSystem(),
            'isDefault' => $account->is_default,
            'defaultModel' => $account->default_model,
            'maxConcurrentTurns' => $account->max_concurrent_turns,
            'status' => $account->status->value,
            'validatedAt' => $account->validated_at?->toIso8601String(),
            'expiresAt' => $account->expires_at?->toIso8601String(),
            'expiresSoon' => $account->expiresSoon(),
            'lastUsedAt' => $account->last_used_at?->toIso8601String(),
            'lastError' => $account->last_error,
            'unattendedRepos' => $account->isSystem()
                ? array_values(Repository::query()->where('unattended_claude_account_id', $account->id)->orderBy('slug')->get()->map(fn (Repository $repository): string => $repository->slug)->all())
                : [],
        ];
    }
}
