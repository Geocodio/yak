<?php

namespace App\Http\Resources;

use App\Models\ClaudeAccount;

/**
 * The page-safe shape of a {@see ClaudeAccount}. The secret is never included.
 */
final class ClaudeAccountData
{
    /**
     * @return array<string, mixed>
     */
    public static function from(ClaudeAccount $account): array
    {
        return [
            'id' => $account->id,
            'name' => $account->name,
            'kind' => $account->kind->value,
            'isSystem' => $account->isSystem(),
            'isDefault' => $account->is_default,
            'defaultModel' => $account->default_model,
            'status' => $account->status->value,
            'validatedAt' => $account->validated_at?->toIso8601String(),
            'expiresAt' => $account->expires_at?->toIso8601String(),
            'lastUsedAt' => $account->last_used_at?->toIso8601String(),
            'lastError' => $account->last_error,
        ];
    }
}
