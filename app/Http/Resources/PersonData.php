<?php

namespace App\Http\Resources;

use App\Models\User;

/**
 * A person on Settings > People. Mirrored by resources/js/types/people.ts.
 */
final class PersonData
{
    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     email: string,
     *     initials: string,
     *     joinedAt: string,
     *     role: 'admin'|'member',
     *     isYou: bool,
     *     isLocked: bool,
     *     lockReason: ?string,
     * }
     */
    public static function from(User $user, User $viewer): array
    {
        $isYou = $user->is($viewer);
        $isAdminByConfig = $user->isAdminByConfig();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'initials' => $user->initials(),
            'joinedAt' => $user->created_at?->toIso8601String() ?? '',
            'role' => $user->isAdmin() ? 'admin' : 'member',
            'isYou' => $isYou,
            'isLocked' => $isYou || $isAdminByConfig,
            'lockReason' => $isYou ? 'You cannot change your own role' : ($isAdminByConfig ? 'Set in server config' : null),
        ];
    }
}
