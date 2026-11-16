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

    /**
     * Slot usage for the System page tiles, counts only.
     *
     * @return array{total: int, used: int, perPersonCap: int, people: list<array{name: string, initials: string, awake: int}>}
     */
    public static function slots(): array
    {
        $people = array_map(fn (array $row): array => [
            'name' => $row['name'],
            'initials' => collect(preg_split('/\s+/', trim($row['name'])) ?: [])->filter()->take(2)->map(fn (string $part): string => mb_strtoupper(mb_substr($part, 0, 1)))->implode(''),
            'awake' => $row['awake'],
        ], self::forAdmin());

        return [
            'total' => (int) config('yak.threads.awake_cap'),
            'used' => array_sum(array_column($people, 'awake')),
            'perPersonCap' => (int) config('yak.threads.person_awake_cap'),
            'people' => $people,
        ];
    }
}
