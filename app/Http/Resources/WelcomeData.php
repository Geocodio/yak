<?php

namespace App\Http\Resources;

use App\Channels\ChannelRegistry;
use App\Models\Repository;
use App\Models\User;

/**
 * The first-run checklist on the Welcome page: a repository, a Claude account
 * that can run a thread, and (optionally) a channel.
 */
final class WelcomeData
{
    /**
     * @return array{
     *     hasRepository: bool,
     *     hasClaudeAccount: bool,
     *     hasChannel: bool,
     *     addRepositoryUrl: string,
     *     channelsUrl: string,
     * }
     */
    public static function forUser(?User $user): array
    {
        $registry = app(ChannelRegistry::class);

        return [
            'hasRepository' => Repository::query()->where('is_active', true)->exists(),
            'hasClaudeAccount' => collect(AccountPickerData::forUser($user)['accounts'])->contains(fn (array $account): bool => $account['usable']),
            'hasChannel' => collect(['slack', 'linear', 'sentry', 'email', 'github', 'drone'])
                ->contains(fn (string $channel): bool => $registry->for($channel)?->enabled() ?? false),
            'addRepositoryUrl' => route('repos.create'),
            'channelsUrl' => route('channels'),
        ];
    }
}
