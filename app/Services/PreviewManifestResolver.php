<?php

namespace App\Services;

use App\Channels\GitHub\AppService;
use App\DataTransferObjects\PreviewManifest;
use App\Models\BranchDeployment;
use Illuminate\Support\Facades\Cache;

/**
 * The preview recipe a deployment runs.
 *
 * A pull request that changes `.yak/preview.yml` previews with its own copy,
 * so a change to the recipe proves itself before it merges. Every other
 * branch runs the default branch's recipe, so a fix there reaches older
 * branches as soon as it merges. A branch file that is missing, invalid or
 * unreadable falls back to the default branch's recipe.
 */
class PreviewManifestResolver
{
    private const PATH = '.yak/preview.yml';

    public function __construct(
        private readonly AppService $github,
        private readonly RepositoryConfigParser $parser,
    ) {}

    public function for(BranchDeployment $deployment): PreviewManifest
    {
        return PreviewManifest::fromArray($this->manifestFor($deployment));
    }

    /**
     * @return array<string, mixed>
     */
    public function manifestFor(BranchDeployment $deployment): array
    {
        $deployment->loadMissing('repository');

        return $this->branchManifest($deployment)
            ?? $deployment->repository->settings()->previewManifest()
            ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function branchManifest(BranchDeployment $deployment): ?array
    {
        $installationId = (int) config('yak.channels.github.installation_id');
        $commitSha = (string) $deployment->current_commit_sha;

        if ($deployment->pr_number === null || $installationId === 0 || $commitSha === '') {
            return null;
        }

        $cacheKey = "preview-manifest:{$deployment->id}:{$commitSha}";

        if (Cache::has($cacheKey)) {
            return Cache::get($cacheKey) ?: null;
        }

        try {
            $manifest = $this->readBranchManifest($installationId, $deployment, $commitSha);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }

        Cache::put($cacheKey, $manifest ?? [], now()->addMinutes(10));

        return $manifest;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readBranchManifest(int $installationId, BranchDeployment $deployment, string $commitSha): ?array
    {
        $slug = (string) $deployment->repository->github_full_name;

        $changesManifest = collect($this->github->listPullRequestFiles($installationId, $slug, (int) $deployment->pr_number))
            ->contains(fn (array $file): bool => ($file['filename'] ?? null) === self::PATH && ($file['status'] ?? null) !== 'removed');

        if (! $changesManifest) {
            return null;
        }

        $content = $this->github->getFileContents($installationId, $slug, self::PATH, $commitSha);

        if ($content === null) {
            return null;
        }

        $result = $this->parser->parse('preview.yml', $content);

        return $result['errors'] === [] && is_array($result['data']) ? $result['data'] : null;
    }
}
