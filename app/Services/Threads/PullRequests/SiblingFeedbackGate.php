<?php

namespace App\Services\Threads\PullRequests;

use App\Channels\GitHub\AppService;
use App\Services\CoOwner\GitHubWriteAccess;
use Illuminate\Support\Facades\Cache;

/**
 * Whether the author of a comment or review on a sibling pull request may
 * steer Yak: a human with write access to that repository on GitHub. A failed
 * lookup is a no, and is never cached.
 */
class SiblingFeedbackGate
{
    public function __construct(private readonly AppService $github) {}

    /** @param array<string, mixed> $user the webhook payload `user` object */
    public function permits(string $repoFullName, array $user): bool
    {
        $login = (string) ($user['login'] ?? '');
        $installationId = (int) config('yak.channels.github.installation_id');

        if (($user['type'] ?? null) === 'Bot' || $login === '' || $installationId <= 0) {
            return false;
        }

        $key = "sibling-feedback:{$repoFullName}:{$login}";
        $cached = Cache::get($key);
        if (is_bool($cached)) {
            return $cached;
        }

        try {
            $verdict = GitHubWriteAccess::grantsWrite($this->github->collaboratorPermission($installationId, $repoFullName, $login));
        } catch (\Throwable $exception) {
            report($exception);

            return false;
        }

        Cache::put($key, $verdict, now()->addMinutes(5));

        return $verdict;
    }
}
