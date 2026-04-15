<?php

namespace App\Services\HealthCheck;

use App\Models\Repository;
use App\Services\GitHubAppService;
use Illuminate\Support\Facades\Http;

/**
 * Verifies the GitHub App installation can reach each active repository.
 *
 * Repos are no longer cloned to the host (sandboxes do their own clones),
 * so this check uses the GitHub API instead of a local `git ls-remote`.
 */
class RepositoriesCheck implements HealthCheck
{
    public function id(): string
    {
        return 'repositories';
    }

    public function name(): string
    {
        return 'Repositories Reachable';
    }

    public function section(): HealthSection
    {
        return HealthSection::System;
    }

    public function run(): HealthResult
    {
        $repos = Repository::where('is_active', true)->get();

        if ($repos->isEmpty()) {
            return HealthResult::ok('No active repositories');
        }

        $installationId = (int) config('yak.channels.github.installation_id');

        if (! $installationId) {
            return HealthResult::error('GitHub App installation_id not configured');
        }

        $token = app(GitHubAppService::class)->getInstallationToken($installationId);

        $total = $repos->count();
        $reachable = 0;
        $failures = [];

        foreach ($repos as $repo) {
            try {
                $response = Http::withToken($token)
                    ->withHeaders(['Accept' => 'application/vnd.github+json'])
                    ->timeout(10)
                    ->get("https://api.github.com/repos/{$repo->slug}");

                if ($response->successful()) {
                    $reachable++;
                } else {
                    $failures[] = $repo->slug;
                }
            } catch (\Throwable) {
                $failures[] = $repo->slug;
            }
        }

        if ($reachable === $total) {
            return HealthResult::ok("{$reachable}/{$total} reachable via GitHub API");
        }

        return HealthResult::error(
            "{$reachable}/{$total} reachable — failed: " . implode(', ', $failures),
        );
    }
}
