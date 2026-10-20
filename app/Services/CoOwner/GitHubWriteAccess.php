<?php

namespace App\Services\CoOwner;

use App\Channels\GitHub\AppService;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

/**
 * Whether a Yak user can write to a repository on GitHub. Checked by GitHub
 * user id, so a renamed login still resolves. Every failure is `error`, which
 * callers treat as no access.
 */
class GitHubWriteAccess
{
    public const ACCESS_CHECK_FAILED_REASON = 'Yak could not check your access on GitHub. Try again in a minute.';

    private const WRITE_ROLES = ['admin', 'maintain', 'write'];

    public function __construct(private AppService $github) {}

    /** One of 'write', 'read', 'unlinked' or 'error'. */
    public function check(Repository $repository, User $user): string
    {
        if (! $user->hasLinkedGitHub()) {
            return 'unlinked';
        }

        $key = $this->cacheKey($repository, $user);
        $cached = Cache::get($key);
        if (is_string($cached)) {
            return $cached;
        }

        try {
            $installationId = (int) config('yak.channels.github.installation_id');
            $login = $this->github->loginForUserId($installationId, (int) $user->github_id);
            if ($login === null) {
                $result = 'read';
            } else {
                if ($login !== $user->github_login) {
                    $user->update(['github_login' => $login]);
                }
                $result = $this->resultFor($this->github->collaboratorPermission($installationId, $repository->github_full_name, $login));
            }
        } catch (\Throwable $exception) {
            report($exception);
            Cache::put($key, 'error', now()->addMinute());

            return 'error';
        }

        Cache::put($key, $result, now()->addMinutes(10));

        return $result;
    }

    public function hasWriteAccess(Repository $repository, User $user): bool
    {
        return $this->check($repository, $user) === 'write';
    }

    public function forget(Repository $repository, User $user): void
    {
        Cache::forget($this->cacheKey($repository, $user));
    }

    /** @param array<string, mixed>|null $permission */
    private function resultFor(?array $permission): string
    {
        if ($permission === null) {
            return 'read';
        }

        $canWrite = in_array($permission['role_name'] ?? null, self::WRITE_ROLES, true)
            || data_get($permission, 'user.permissions.push') === true;

        return $canWrite ? 'write' : 'read';
    }

    private function cacheKey(Repository $repository, User $user): string
    {
        return "co-owner:write-access:{$repository->id}:{$user->github_id}";
    }
}
