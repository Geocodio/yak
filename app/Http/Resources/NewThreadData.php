<?php

namespace App\Http\Resources;

use App\Models\Repository;
use App\Models\User;
use App\Services\Threads\Repos\RepositorySetupProgress;

/**
 * What the new-thread form needs: the active repositories, the account picker
 * and the repository to preselect. Shared by the `Threads/New` page and the
 * new-thread dialog in the app shell.
 */
final class NewThreadData
{
    /**
     * @return array{
     *     repos: list<array{slug: string, name: string, setupStatus: 'pending'|'running'|'ready'|'failed', setupStep: ?string}>,
     *     picker: array<string, mixed>,
     *     defaultRepo: ?string,
     * }
     */
    public static function for(?User $user): array
    {
        $repositories = Repository::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('slug')->get();

        $defaultRepo = $repositories->firstWhere('is_default', true) ?? ($repositories->count() === 1 ? $repositories->first() : null);

        $repos = [];

        foreach ($repositories as $repository) {
            $progress = RepositorySetupProgress::for($repository);
            $repos[] = ['slug' => $repository->slug, 'name' => $repository->name, 'setupStatus' => $progress['status'], 'setupStep' => $progress['step']];
        }

        return [
            'repos' => $repos,
            'picker' => AccountPickerData::forUser($user),
            'defaultRepo' => $defaultRepo?->slug,
        ];
    }
}
