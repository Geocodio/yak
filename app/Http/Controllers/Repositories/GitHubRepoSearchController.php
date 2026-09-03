<?php

namespace App\Http\Controllers\Repositories;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\Http\Controllers\Controller;
use App\Models\Repository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class GitHubRepoSearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $query = $request->string('q')->toString();

        return response()->json([
            'repos' => $this->filteredRepos($this->installationRepos(), $query),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function installationRepos(): array
    {
        try {
            $installationId = (int) config('yak.channels.github.installation_id');

            if (! $installationId) {
                return [];
            }

            return Cache::remember(
                'github-installation-repos',
                300,
                fn (): array => app(GitHubAppService::class)->listInstallationRepositories($installationId),
            );
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $repos
     * @return array<int, array{id: ?int, fullName: string, name: string, description: ?string, defaultBranch: string, cloneUrl: string, private: bool, language: ?string, pushedAt: ?string}>
     */
    private function filteredRepos(array $repos, string $query): array
    {
        // Match on both keys: a repository renamed on GitHub is still
        // tracked under its original slug, and must not look addable.
        $alreadyAdded = array_flip(array_merge(
            Repository::pluck('slug')->all(),
            Repository::whereNotNull('github_full_name')->pluck('github_full_name')->all(),
        ));

        $available = array_filter(
            $repos,
            fn (array $repo): bool => ! isset($alreadyAdded[$repo['full_name']]),
        );

        if ($query !== '') {
            $needle = strtolower($query);
            $available = array_filter(
                $available,
                fn (array $repo): bool => str_contains(strtolower($repo['name']), $needle)
                    || str_contains(strtolower($repo['full_name']), $needle),
            );
        }

        return array_map(fn (array $repo): array => [
            'id' => isset($repo['id']) ? (int) $repo['id'] : null,
            'fullName' => $repo['full_name'],
            'name' => $repo['name'],
            'description' => $repo['description'] ?? null,
            'defaultBranch' => $repo['default_branch'],
            'cloneUrl' => $repo['clone_url'],
            'private' => (bool) ($repo['private'] ?? false),
            'language' => $repo['language'] ?? null,
            'pushedAt' => $repo['pushed_at'] ?? null,
        ], array_slice(array_values($available), 0, 50));
    }
}
