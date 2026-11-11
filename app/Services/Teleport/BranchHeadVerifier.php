<?php

namespace App\Services\Teleport;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Models\Repository;

/**
 * Reads the head a branch has on GitHub, so a teleport proves the push is visible.
 */
class BranchHeadVerifier
{
    public function __construct(private readonly GitHubAppService $gitHub) {}

    public function headOf(string $repo, string $branch): ?string
    {
        $name = strtolower($repo);
        $repository = Repository::query()->whereRaw('LOWER(github_full_name) = ?', [$name])->first()
            ?? Repository::query()->whereRaw('LOWER(slug) = ?', [$name])->first();

        if ($repository === null) {
            return null;
        }

        return $this->gitHub->getBranchHeadSha((int) config('yak.channels.github.installation_id'), $repository->github_full_name, $branch);
    }
}
