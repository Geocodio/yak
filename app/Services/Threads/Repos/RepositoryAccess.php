<?php

namespace App\Services\Threads\Repos;

use App\Channels\GitHub\AppService;
use App\DataTransferObjects\RepositoryAccessResult;
use App\Models\Repository;
use Throwable;

/**
 * Whether Yak may clone a repository into a thread: it must be a known, active
 * repository and the GitHub App installation must be able to read it.
 */
class RepositoryAccess
{
    public function __construct(private AppService $github) {}

    public function check(string $slug): RepositoryAccessResult
    {
        $denied = new RepositoryAccessResult(false, "Install the Yak GitHub app on {$slug}, or ask an admin.");

        if (! Repository::query()->where('slug', $slug)->where('is_active', true)->exists()) {
            return $denied;
        }

        try {
            $reachable = $this->github->getRepository((int) config('yak.channels.github.installation_id'), $slug) !== null;
        } catch (Throwable) {
            $reachable = false;
        }

        return $reachable ? new RepositoryAccessResult(true) : $denied;
    }
}
