<?php

namespace App\Channels\GitHub;

use App\Channels\Contracts\CIBuildScanner;
use App\DataTransferObjects\CIBuildFailure;
use App\Models\Repository;
use App\Services\TestFailureParser;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class ActionsBuildScanner implements CIBuildScanner
{
    public function __construct(
        private readonly AppService $gitHubAppService,
        private readonly TestFailureParser $parser = new TestFailureParser,
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
            ->get("https://api.github.com/repos/{$repository->github_full_name}/actions/runs", [
                'branch' => $repository->default_branch,
                'status' => 'failure',
                'created' => '>=' . $cutoff,
                'per_page' => 10,
            ])
            ->throw()
            ->json();

        /** @var array<int, array{id: int, html_url: string, conclusion: string, created_at: string, head_branch?: string|null, head_sha?: string|null}> $runs */
        $runs = $response['workflow_runs'] ?? [];

        $failures = collect();

        foreach ($runs as $run) {
            foreach ($this->failedTestJobs($client, $repository->github_full_name, (int) $run['id']) as $job) {
                $log = $this->getJobLog($client, $repository->github_full_name, (int) $job['id']);

                if ($log === null) {
                    continue;
                }

                foreach ($this->parser->parse($log) as $failure) {
                    $failures->push(new CIBuildFailure(
                        testName: $failure['test'],
                        output: $failure['output'],
                        buildUrl: $run['html_url'],
                        buildId: (string) $run['id'],
                        branch: $run['head_branch'] ?? null,
                        commitSha: $run['head_sha'] ?? null,
                    ));
                }
            }
        }

        return $failures;
    }

    /**
     * The failed jobs in a run that actually ran tests.
     *
     * A workflow run mixes test jobs with build, lint, deploy and notify
     * jobs. Only test jobs can produce a flaky test, and their logs run to
     * megabytes apiece — so the filter is both a correctness guard and the
     * thing that keeps this scan affordable.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function failedTestJobs(PendingRequest $client, string $repoSlug, int $runId): array
    {
        /** @var array<string, mixed> $response */
        $response = $client
            ->get("https://api.github.com/repos/{$repoSlug}/actions/runs/{$runId}/jobs", [
                'filter' => 'latest',
                'per_page' => 100,
            ])
            ->throw()
            ->json();

        /** @var array<int, array{id: int, name: string, conclusion?: string|null}> $jobs */
        $jobs = $response['jobs'] ?? [];

        return array_values(array_filter(
            $jobs,
            fn (array $job): bool => ($job['conclusion'] ?? null) === 'failure'
                && $this->isTestJob($job['name']),
        ));
    }

    private function isTestJob(string $jobName): bool
    {
        /** @var array<int, string> $excluded */
        $excluded = config('yak.ci_scan.test_job_exclude_patterns', []);

        if (Str::contains($jobName, $excluded, ignoreCase: true)) {
            return false;
        }

        /** @var array<int, string> $patterns */
        $patterns = config('yak.ci_scan.test_job_patterns', []);

        return Str::contains($jobName, $patterns, ignoreCase: true);
    }

    /**
     * Fetch a job's raw log. GitHub answers with a redirect to short-lived
     * blob storage; the HTTP client follows it for us.
     *
     * Returns null when the log is gone. GitHub expires job logs well before
     * it forgets the run itself, so a 404 here is an ordinary fact about an
     * older run — skip the job rather than abandoning the repository's whole
     * scan. Every other failure still throws: a 403 means the App is missing
     * `Actions: Read`, and silently reporting "no flaky tests" for a
     * permissions problem is the bug this scanner just came out of.
     */
    private function getJobLog(PendingRequest $client, string $repoSlug, int $jobId): ?string
    {
        $response = $client->get("https://api.github.com/repos/{$repoSlug}/actions/jobs/{$jobId}/logs");

        if ($response->status() === 404) {
            return null;
        }

        return $response->throw()->body();
    }
}
