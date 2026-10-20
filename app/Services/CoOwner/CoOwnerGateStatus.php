<?php

namespace App\Services\CoOwner;

use App\Channels\GitHub\AppService;
use App\Http\Resources\RiskProfilePageData;
use App\Models\Repository;
use App\Services\RepositoryConfig;
use App\Support\CodeOwners;
use Illuminate\Support\Facades\Cache;

/**
 * The read-only co-owner gate status shown on the repository page: the mode
 * from `.yak/config.yml`, the risk profile in use, how many CODEOWNERS owners
 * resolve to linked users with write access, and whether the fallback owner
 * does. Cached per repository and default-branch commit for 10 minutes.
 */
class CoOwnerGateStatus
{
    private const CACHE_MINUTES = 10;

    /** Upper bound on CODEOWNERS entries resolved, since each can cost API calls. */
    private const MAX_ENTRIES = 50;

    public function __construct(
        private CoOwnerResolver $resolver,
        private GitHubWriteAccess $writeAccess,
        private RepositoryConfig $config,
        private AppService $github,
    ) {}

    /**
     * @return array{mode: string, source: string, profile: ?array{path: ?string, areaCount: int}, owners: array{resolvable: list<string>, total: int, resolvedEntries: int, teamsSkipped: list<string>}, fallbackOwner: ?array{name: string, resolves: bool}, appSettingsUrl: ?string}
     */
    public function for(Repository $repository): array
    {
        $snapshot = $this->config->snapshot($repository);
        $sha = $snapshot->commitSha;

        $status = $sha === null
            ? $this->compute($repository, null)
            : Cache::remember(
                "co-owner:gate-status:{$repository->id}:{$sha}",
                now()->addMinutes(self::CACHE_MINUTES),
                fn (): array => $this->compute($repository, $sha),
            );

        $status['fallbackOwner'] = $this->fallbackOwner($repository);

        return $status;
    }

    /**
     * Resolves each distinct CODEOWNERS entry.
     *
     * @return array{resolvable: list<string>, total: int, resolvedEntries: int, teamsSkipped: list<string>, unresolved: list<string>}
     */
    public function ownersFor(Repository $repository, CodeOwners $codeOwners): array
    {
        $entries = array_slice($codeOwners->owners(), 0, self::MAX_ENTRIES);
        $names = [];
        $resolvedEntries = 0;
        $teamsSkipped = [];
        $unresolved = [];

        foreach ($entries as $entry) {
            $resolved = $this->resolver->resolveEntry($repository, $entry);

            foreach ($resolved['users'] as $user) {
                $names[$user->id] = $user->name;
            }

            if ($resolved['users'] === []) {
                $unresolved[] = $entry;
            } else {
                $resolvedEntries++;
            }

            if ($resolved['outcome'] === 'team_skipped') {
                $teamsSkipped[] = $entry;
            }
        }

        return [
            'resolvable' => array_values($names),
            'total' => count($entries),
            'resolvedEntries' => $resolvedEntries,
            'teamsSkipped' => $teamsSkipped,
            'unresolved' => $unresolved,
        ];
    }

    /** @return ?array{name: string, resolves: bool} */
    public function fallbackOwner(Repository $repository): ?array
    {
        $user = $repository->defaultResponsibleUser;

        return $user === null ? null : [
            'name' => $user->name,
            'resolves' => $this->writeAccess->hasWriteAccess($repository, $user),
        ];
    }

    /**
     * @return array{mode: string, source: string, profile: ?array{path: ?string, areaCount: int}, owners: array{resolvable: list<string>, total: int, resolvedEntries: int, teamsSkipped: list<string>}, fallbackOwner: ?array{name: string, resolves: bool}, appSettingsUrl: ?string}
     */
    private function compute(Repository $repository, ?string $sha): array
    {
        $settings = $repository->settings();
        $profile = RiskProfilePageData::effectiveProfile($repository, $settings);
        $isFile = $settings->riskProfile() !== null;
        $owners = $sha === null
            ? ['resolvable' => [], 'total' => 0, 'resolvedEntries' => 0, 'teamsSkipped' => [], 'unresolved' => []]
            : $this->ownersFor($repository, RiskProfilePageData::codeOwners($repository, $sha));
        unset($owners['unresolved']);

        return [
            'mode' => $settings->coOwnerGateMode(),
            'source' => $isFile ? 'file' : ($profile === null ? 'none' : 'database'),
            'profile' => $profile === null ? null : [
                'path' => $isFile ? '.yak/risk-profile.yml' : null,
                'areaCount' => count($profile['areas'] ?? []),
            ],
            'owners' => $owners,
            'fallbackOwner' => null,
            'appSettingsUrl' => $this->appSettingsUrl($repository),
        ];
    }

    /** The installation settings page, or null when the owner type cannot be read. */
    private function appSettingsUrl(Repository $repository): ?string
    {
        $owner = explode('/', (string) $repository->github_full_name)[0];
        $installationId = (int) config('yak.channels.github.installation_id');

        try {
            $type = $this->github->installationClient($installationId)->timeout(5)
                ->get("https://api.github.com/repos/{$repository->github_full_name}")->json('owner.type');
        } catch (\Throwable) {
            return null;
        }

        return match ($type) {
            'Organization' => "https://github.com/organizations/{$owner}/settings/installations/{$installationId}",
            'User' => "https://github.com/settings/installations/{$installationId}",
            default => null,
        };
    }
}
