<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Services\Threads\SandboxCapacity;

/**
 * Per person count of awake sandboxes, for admins. Counts the same slots the
 * person share limit counts: starting, running and paused sandboxes, each
 * shared container once, with sub-threads under the owner of their stack.
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

        $counts = app(SandboxCapacity::class)->awakeSlots()
            ->whereNotNull('effective_owner_id')
            ->countBy('effective_owner_id')
            ->sortDesc();

        $names = User::query()->whereIn('id', $counts->keys())->pluck('name', 'id');

        return array_values($counts
            ->map(fn (int $awake, int|string $userId): array => ['name' => (string) ($names[$userId] ?? 'Unknown'), 'awake' => $awake, 'cap' => $cap])
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
            'used' => app(SandboxCapacity::class)->awakeCount(),
            'perPersonCap' => (int) config('yak.threads.person_awake_cap'),
            'people' => $people,
        ];
    }
}
