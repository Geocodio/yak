<?php

namespace App\Services;

use App\Contracts\CIBuildScanner;
use App\DataTransferObjects\CIBuildFailure;
use App\Models\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class GitHubActionsBuildScanner implements CIBuildScanner
{
    public function __construct(
        private readonly GitHubAppService $gitHubAppService,
    ) {}

    /**
     * @return Collection<int, CIBuildFailure>
     */
    public function getRecentFailures(Repository $repository, int $maxAgeHours): Collection
    {
        $installationId = (int) config('yak.channels.github.installation_id');
        $token = $this->gitHubAppService->getInstallationToken($installationId);
        $cutoff = now()->subHours($maxAgeHours)->toIso8601String();

        /** @var array<string, mixed> $response */
        $response = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get("https://api.github.com/repos/{$repository->slug}/actions/runs", [
                'branch' => $repository->default_branch,
                'status' => 'failure',
                'created' => '>=' . $cutoff,
                'per_page' => 10,
            ])
            ->json();

        /** @var array<int, array{id: int, html_url: string, conclusion: string, created_at: string, head_branch: string}> $runs */
        $runs = $response['workflow_runs'] ?? [];

        $failures = collect();

        foreach ($runs as $run) {
            $annotations = $this->getFailureAnnotations($token, $repository->slug, (int) $run['id']);

            foreach ($annotations as $annotation) {
                $failures->push(new CIBuildFailure(
                    testName: $annotation['title'],
                    output: $annotation['message'],
                    buildUrl: $run['html_url'],
                    buildId: (string) $run['id'],
                ));
            }
        }

        return $failures;
    }

    /**
     * @return array<int, array{title: string, message: string}>
     */
    private function getFailureAnnotations(string $token, string $repoSlug, int $runId): array
    {
        /** @var array<string, mixed> $checkRunsResponse */
        $checkRunsResponse = Http::withToken($token)
            ->withHeaders(['Accept' => 'application/vnd.github+json'])
            ->get("https://api.github.com/repos/{$repoSlug}/actions/runs/{$runId}/check-runs", [
                'filter' => 'latest',
            ])
            ->json();

        /** @var array<int, array{id: int}> $checkRuns */
        $checkRuns = $checkRunsResponse['check_runs'] ?? [];
        $annotations = [];

        foreach ($checkRuns as $checkRun) {
            /** @var array<int, array{annotation_level: string, title: string, message: string}> $checkAnnotations */
            $checkAnnotations = Http::withToken($token)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get("https://api.github.com/repos/{$repoSlug}/check-runs/{$checkRun['id']}/annotations")
                ->json();

            foreach ($checkAnnotations as $annotation) {
                if ($annotation['annotation_level'] === 'failure') {
                    $annotations[] = [
                        'title' => $annotation['title'],
                        'message' => $annotation['message'],
                    ];
                }
            }
        }

        return $annotations;
    }
}
