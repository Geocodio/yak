<?php

namespace App\Http\Resources;

use App\Models\GithubKeySync;
use App\Models\SshKey;
use App\Models\User;
use App\Services\Ssh\GitHubKeyDirectory;
use Illuminate\Support\Collection;

/**
 * Props of Settings/SshKeys. Mirrored by resources/js/types/sshKeys.ts.
 */
final class SshKeysPageData
{
    /**
     * @return array{
     *     githubKeys: list<array{id: int, name: string, type: string, fingerprint: string, source: string, addedAt: string}>,
     *     uploadedKeys: list<array{id: int, name: string, type: string, fingerprint: string, source: string, addedAt: string}>,
     *     github: array{linked: bool, login: ?string, profileUrl: ?string, status: string, isStale: bool, checkedAt: ?string},
     *     config: array{snippet: string, command: string, docsUrl: string, jumpHost: string},
     * }
     */
    public static function for(User $user): array
    {
        $keys = $user->sshKeys()->orderBy('id')->get();
        $sync = GithubKeySync::query()->find($user->id);
        $login = $sync->github_login ?? $user->github_login;
        $jumpHost = self::jumpHost();
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return [
            'githubKeys' => self::rows($keys, 'github'),
            'uploadedKeys' => self::rows($keys, 'uploaded'),
            'github' => [
                'linked' => $user->hasLinkedGitHub(),
                'login' => $login,
                'profileUrl' => $login === null ? null : "https://github.com/{$login}",
                'status' => $sync->status ?? ($user->hasLinkedGitHub() ? 'pending' : 'unknown_account'),
                'isStale' => app(GitHubKeyDirectory::class)->isStale($user),
                'checkedAt' => $sync?->checked_at?->toIso8601String(),
            ],
            'config' => [
                'snippet' => implode("\n", [
                    "Host *.{$host}",
                    '  User yak',
                    "  ProxyJump yak-jump@{$jumpHost}",
                    '  IdentityFile ~/.ssh/id_ed25519',
                ]),
                'command' => 'yak ssh --config <thread id>',
                'docsUrl' => config('docs.base_url') . 'ssh/',
                'jumpHost' => $jumpHost,
            ],
        ];
    }

    /**
     * @param  Collection<int, SshKey>  $keys
     * @return list<array{id: int, name: string, type: string, fingerprint: string, source: string, addedAt: string}>
     */
    private static function rows(Collection $keys, string $source): array
    {
        return array_values($keys->filter(fn (SshKey $key): bool => $key->source->value === $source)
            ->map(fn (SshKey $key): array => SshKeyData::from($key))
            ->all());
    }

    private static function jumpHost(): string
    {
        $configured = config('yak.ssh.jump_host');

        return is_string($configured) && $configured !== ''
            ? $configured
            : 'ssh.' . parse_url((string) config('app.url'), PHP_URL_HOST) . ':2222';
    }
}
