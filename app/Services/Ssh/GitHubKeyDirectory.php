<?php

namespace App\Services\Ssh;

use App\Enums\SshKeySource;
use App\Models\GithubKeySync;
use App\Models\SshKey;
use App\Models\User;
use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Caches the public keys on a user's GitHub account. The numeric GitHub id is the identity; the login is resolved from
 * it on every refresh so a rename is followed and a reused old login is never consulted.
 */
class GitHubKeyDirectory
{
    public function refresh(User $user, bool $force = false): GithubKeySync
    {
        if ($user->github_id === null) {
            return new GithubKeySync(['user_id' => $user->id, 'status' => 'unknown_account']);
        }

        $existing = GithubKeySync::query()->find($user->id);

        if (! $force && $existing !== null && $this->isFresh($existing, $user)) {
            return $existing;
        }

        try {
            $login = $this->resolveLogin($user->github_id);
            $keys = $login === null ? [] : $this->fetchKeys($login);
        } catch (Exception $exception) {
            return $this->recordError($user, $existing, $exception->getMessage());
        }

        $this->replaceKeys($user, $keys);

        return $this->saveSync($user, [
            'github_login' => $login ?? $existing?->github_login,
            'status' => $login === null ? 'unknown_account' : ($keys === [] ? 'empty' : 'ok'),
            'fetched_at' => now(),
            'checked_at' => now(),
            'stale_since' => null,
            'last_error' => null,
        ]);
    }

    public function isStale(User $user): bool
    {
        $sync = GithubKeySync::query()->find($user->id);

        return $sync !== null && $sync->status === 'error' && $sync->stale_since !== null;
    }

    private function isFresh(GithubKeySync $sync, User $user): bool
    {
        if ($sync->checked_at === null || $sync->github_id !== $user->github_id) {
            return false;
        }

        $ttl = match ($sync->status) {
            'ok' => (int) config('yak.ssh.github_ttl_seconds'),
            'empty', 'unknown_account' => (int) config('yak.ssh.github_negative_ttl_seconds'),
            default => 0,
        };

        return $sync->checked_at->addSeconds($ttl)->isFuture();
    }

    /**
     * @throws RuntimeException when GitHub cannot answer
     */
    private function resolveLogin(int $githubId): ?string
    {
        $response = $this->get("https://api.github.com/user/{$githubId}");

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new RuntimeException("GitHub returned {$response->status()} resolving account {$githubId}");
        }

        $login = $response->json('login');

        return is_string($login) && preg_match('/^[A-Za-z0-9-]{1,39}$/', $login) === 1 ? $login : null;
    }

    /**
     * @return list<SshPublicKey>
     *
     * @throws RuntimeException when GitHub cannot answer
     */
    private function fetchKeys(string $login): array
    {
        $response = $this->get("https://github.com/{$login}.keys");

        if ($response->status() === 404) {
            return [];
        }

        if (! $response->successful()) {
            throw new RuntimeException("GitHub returned {$response->status()} listing keys for {$login}");
        }

        $keys = [];

        foreach (preg_split('/\R/', $response->body()) ?: [] as $line) {
            if (trim($line) === '') {
                continue;
            }

            try {
                $key = SshPublicKey::parse($line);
                $keys[$key->fingerprint] = $key;
            } catch (InvalidSshKey) {
                continue;
            }
        }

        return array_values($keys);
    }

    private function get(string $url): Response
    {
        return Http::timeout(10)->get($url);
    }

    /**
     * @param  list<SshPublicKey>  $keys
     */
    private function replaceKeys(User $user, array $keys): void
    {
        $wanted = array_map(fn (SshPublicKey $key): string => $key->fingerprint, $keys);

        SshKey::query()->where('user_id', $user->id)->fromGithub()->whereNotIn('fingerprint', $wanted)->delete();

        $position = 0;

        foreach ($keys as $key) {
            $position++;
            $owner = SshKey::query()->where('fingerprint', $key->fingerprint)->value('user_id');

            if ($owner === null) {
                SshKey::query()->create([
                    'user_id' => $user->id,
                    'source' => SshKeySource::Github,
                    'name' => "GitHub key {$position}",
                    'type' => $key->type,
                    'public_key' => $key->line(),
                    'fingerprint' => $key->fingerprint,
                ]);
            } elseif ($owner !== $user->id) {
                Log::channel('yak')->warning('Skipped a GitHub key already owned by another Yak account', [
                    'user_id' => $user->id,
                    'fingerprint' => $key->fingerprint,
                ]);
            }
        }
    }

    private function recordError(User $user, ?GithubKeySync $existing, string $message): GithubKeySync
    {
        $staleSince = $existing?->status === 'error' && $existing->stale_since !== null ? $existing->stale_since : now();

        if ($staleSince->addDays((int) config('yak.ssh.github_stale_days'))->isPast()) {
            SshKey::query()->where('user_id', $user->id)->fromGithub()->delete();
        }

        return $this->saveSync($user, [
            'status' => 'error',
            'checked_at' => now(),
            'stale_since' => $staleSince,
            'last_error' => $message,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function saveSync(User $user, array $attributes): GithubKeySync
    {
        return GithubKeySync::query()->updateOrCreate(
            ['user_id' => $user->id],
            ['github_id' => $user->github_id, ...$attributes],
        );
    }
}
