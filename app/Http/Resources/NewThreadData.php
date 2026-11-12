<?php

namespace App\Http\Resources;

use App\Channels\ChannelRegistry;
use App\Models\Repository;
use App\Models\User;

/**
 * The props the start-a-thread form needs, shared by the New thread page and
 * the Welcome page.
 */
final class NewThreadData
{
    /**
     * @return array{
     *     repos: array<int, array{slug: string, name: string}>,
     *     picker: array<string, mixed>,
     *     defaultRepo: string|null,
     *     emailToYakAddress: string|null,
     * }
     */
    public static function forUser(?User $user): array
    {
        $repositories = Repository::query()->where('is_active', true)->orderByDesc('is_default')->orderBy('slug')->get();

        $defaultRepo = $repositories->firstWhere('is_default', true) ?? ($repositories->count() === 1 ? $repositories->first() : null);

        return [
            'repos' => $repositories->map(fn (Repository $repository): array => ['slug' => $repository->slug, 'name' => $repository->name])->values()->all(),
            'picker' => AccountPickerData::forUser($user),
            'defaultRepo' => $defaultRepo?->slug,
            'emailToYakAddress' => app(ChannelRegistry::class)->for('email')?->enabled() === true
                ? (string) config('yak.channels.email.inbound_address')
                : null,
        ];
    }
}
