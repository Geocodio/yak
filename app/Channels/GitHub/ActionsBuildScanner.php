<?php

namespace App\Channels\GitHub;

use App\Channels\Contracts\CIBuildScanner;
use App\DataTransferObjects\CIBuildFailure;
use App\Models\Repository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;

class ActionsBuildScanner implements CIBuildScanner
{
    public function __construct(
        private readonly AppService $gitHubAppService,
    ) {}

    /**
     * @return Collection<int, CIBuildFailure>
     */
    public function getRecentFailures(Repository $repository, int $maxAgeHours): Collection
    {
        $installationId = (int) config('yak.channels.github.installation_id');
        $client = $this->gitHubAppService->installationClient($installationId);
        $cutoff = now()->subHours($maxAgeHours)->toIso8601String();

        /** @var array<string, mixed> $response */
        $response = $client
            ->get("https://api.github.com/repos/{$repository->slug}/actions/runs", [
                'branch' => $repository->default_branch,
                'status' => 'failure',
                'created' => '>=' . $cutoff,
                'per_page' => 10,
            ])
            ->json();

        /** @var array<int, array{id: int, html_url: string, conclusion: string, created_at: string, head_branch?: string|null, head_sha?: string|null}> $runs */
        $runs = $response['workflow_runs'] ?? [];

        $failures = collect();

        foreach ($runs as $run) {
            $annotations = $this->getFailureAnnotations($client, $repository->slug, (int) $run['id']);

            foreach ($annotations as $annotation) {
                $failures->push(new CIBuildFailure(
                    testName: $annotation['title'],
                    output: $annotation['message'],
                    buildUrl: $run['html_url'],
                    buildId: (string) $run['id'],
                    branch: $run['head_branch'] ?? null,
                    commitSha: $run['head_sha'] ?? null,
                ));
            }
        }

        return $failures;
    }

    /**
     * @return array<int, array{title: string, message: string}>
     */
    private function getFailureAnnotations(PendingRequest $client, string $repoSlug, int $runId): array
    {
        /** @var array<string, mixed> $checkRunsResponse */
        $checkRunsResponse = $client
            ->get("https://api.github.com/repos/{$repoSlug}/actions/runs/{$runId}/check-runs", [
                'filter' => 'latest',
            ])
            ->json();

        /** @var array<int, array{id: int}> $checkRuns */
        $checkRuns = $checkRunsResponse['check_runs'] ?? [];
        $annotations = [];

        foreach ($checkRuns as $checkRun) {
            /** @var array<int, array{annotation_level: string, title: string, message: string}> $checkAnnotations */
            $checkAnnotations = $client
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
