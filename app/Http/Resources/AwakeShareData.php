<?php

namespace App\Http\Resources;

use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Models\User;

/**
 * Per person count of threads with a running sandbox, for admins.
 * Counts only: no thread title or id leaves this class, so private threads stay private.
 */
final class AwakeShareData
{
    /**
     * @return list<array{name: string, awake: int, cap: int}>
     */
    public static function forAdmin(): array
    {
        $cap = (int) config('yak.threads.person_awake_cap');

        $counts = Thread::query()
            ->withoutVisibilityScope()
            ->where('sandbox_state', ThreadSandboxState::Running)
            ->whereNotNull('owner_user_id')
            ->selectRaw('owner_user_id, count(*) as awake')
            ->groupBy('owner_user_id')
            ->orderByDesc('awake')
            ->pluck('awake', 'owner_user_id');

        $names = User::query()->whereIn('id', $counts->keys())->pluck('name', 'id');

        return array_values($counts
            ->map(fn ($awake, $userId): array => ['name' => (string) ($names[$userId] ?? 'Unknown'), 'awake' => (int) $awake, 'cap' => $cap])
            ->all());
    }
}
