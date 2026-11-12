<?php

namespace App\Http\Resources;

use App\Models\Repository;
use App\Models\User;

/**
 * What the new-thread form needs: the active repositories, the account picker
 * and the repository to preselect. Shared by the `Threads/New` page and the
 * new-thread dialog in the app shell.
 */
final class NewThreadData
{
    /**
     * @return array{
     *     repos: list<array{slug: string, name: string}>,
     *     picker: array<string, mixed>,
     *     defaultRepo: ?string,
     * }
     */
    public static function for(?User $user): array
    {
        $repositories = Repository::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('slug')->get();

        $defaultRepo = $repositories->firstWhere('is_default', true) ?? ($repositories->count() === 1 ? $repositories->first() : null);

        return [
            'repos' => array_values($repositories->map(fn (Repository $repository): array => ['slug' => $repository->slug, 'name' => $repository->name])->all()),
            'picker' => AccountPickerData::forUser($user),
            'defaultRepo' => $defaultRepo?->slug,
        ];
    }
}
