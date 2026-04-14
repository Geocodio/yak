<?php

namespace App\Services;

use App\Contracts\CIBuildScanner;
use App\DataTransferObjects\BuildResult;
use App\DataTransferObjects\CIBuildFailure;
use App\Models\Repository;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class DroneBuildScanner implements CIBuildScanner
{
    /**
     * @return Collection<int, CIBuildFailure>
     */
    public function getRecentFailures(Repository $repository, int $maxAgeHours): Collection
    {
        $droneUrl = (string) config('yak.channels.drone.url');
        $droneToken = (string) config('yak.channels.drone.token');
        $cutoff = now()->subHours($maxAgeHours);

        // Scan all branches — flaky tests surface on PR branches first, so
        // restricting to default_branch misses them. Dedup by test name in
        // the caller keeps task volume sane.
        /** @var array<int, array{number: int, status: string, source: string, started: int, link: string}> $builds */
        $builds = Http::withToken($droneToken)
            ->get("{$droneUrl}/api/repos/{$repository->slug}/builds")
            ->json();

        $failures = collect();

        foreach ($builds as $build) {
            if ($build['status'] !== 'failure') {
                continue;
            }

            $buildTime = Carbon::createFromTimestamp($build['started']);
            if ($buildTime->isBefore($cutoff)) {
                continue;
            }

            $buildUrl = $build['link'];
            $logs = $this->getBuildLogs($droneUrl, $droneToken, $repository->slug, $build['number']);
            $testFailures = $this->parseTestFailures($logs);

            foreach ($testFailures as $failure) {
                $failures->push(new CIBuildFailure(
                    testName: $failure['test'],
                    output: $failure['output'],
                    buildUrl: $buildUrl,
                    buildId: (string) $build['number'],
                ));
            }
        }

        return $failures;
    }

    /**
     * Poll the latest Drone build for a given branch.
     *
     * Used to resolve the "awaiting_ci" stage for Drone-backed repos, since
     * Drone doesn't support outbound webhooks. Returns null while the build
     * is still in flight so the caller can check again on the next tick.
     *
     * `$notBefore` guards against the retry race: after a task re-enters
     * awaiting_ci with a fresh push, an older failed build on the same
     * branch must not be picked up as the "current" result.
     */
    public function pollBranchStatus(
        Repository $repository,
        string $branch,
        DateTimeInterface $notBefore,
    ): ?BuildResult {
        $droneUrl = (string) config('yak.channels.drone.url');
        $droneToken = (string) config('yak.channels.drone.token');

        /** @var array<int, array{number: int, status: string, started: int, link: string, after?: string}> $builds */
        $builds = Http::withToken($droneToken)
            ->get("{$droneUrl}/api/repos/{$repository->slug}/builds", ['branch' => $branch])
            ->json() ?? [];

        // 60s grace: Drone can lag a few seconds behind the git push.
        $cutoff = $notBefore->getTimestamp() - 60;

        $build = collect($builds)
            ->filter(fn (array $b) => ($b['started'] ?? 0) >= $cutoff)
            ->sortByDesc('started')
            ->first();

        if ($build === null) {
            return null;
        }

        // Drone build statuses: pending, running, blocked, waiting_on_deps,
        // success, failure, error, killed, declined, skipped. Treat anything
        // non-terminal (or `skipped`, which means no CI actually ran) as
        // "keep waiting" so the caller tries again.
        return match ($build['status']) {
            'success' => new BuildResult(
                passed: true,
                externalId: (string) $build['number'],
                repository: $repository->slug,
                commitSha: $build['after'] ?? null,
                metadata: ['build_url' => $build['link']],
            ),
            'failure', 'error', 'killed', 'declined' => new BuildResult(
                passed: false,
                externalId: (string) $build['number'],
                repository: $repository->slug,
                output: $this->getBuildLogs($droneUrl, $droneToken, $repository->slug, $build['number']),
                commitSha: $build['after'] ?? null,
                metadata: ['build_url' => $build['link']],
            ),
            default => null,
        };
    }

    private function getBuildLogs(string $droneUrl, string $droneToken, string $repoSlug, int $buildNumber): string
    {
        /** @var array<int, array{number: int, steps?: array<int, array{number: int, status: string}>}> $stages */
        $stages = Http::withToken($droneToken)
            ->get("{$droneUrl}/api/repos/{$repoSlug}/builds/{$buildNumber}")
            ->json('stages') ?? [];

        $logs = '';

        foreach ($stages as $stage) {
            foreach ($stage['steps'] ?? [] as $step) {
                // Only failing steps produce useful test output; fetching
                // every step would explode the log size and still not help
                // the parser.
                if ($step['status'] !== 'failure') {
                    continue;
                }

                /** @var array<int, array{out: string}> $stepLog */
                $stepLog = Http::withToken($droneToken)
                    ->get("{$droneUrl}/api/repos/{$repoSlug}/builds/{$buildNumber}/logs/{$stage['number']}/{$step['number']}")
                    ->json() ?? [];

                foreach ($stepLog as $line) {
                    $logs .= ($line['out'] ?? '') . "\n";
                }
            }
        }

        return $logs;
    }

    /**
     * @return array<int, array{test: string, output: string}>
     */
    private function parseTestFailures(string $output): array
    {
        $failures = [];
        $lines = explode("\n", $output);

        $currentTest = null;
        $currentOutput = '';
        $inFailure = false;

        foreach ($lines as $line) {
            if (preg_match('/FAILED\s+(.+)/', $line, $matches)) {
                if ($currentTest !== null) {
                    $failures[] = [
                        'test' => $currentTest,
                        'output' => trim($currentOutput),
                    ];
                }

                $currentTest = trim($matches[1]);
                $currentOutput = $line . "\n";
                $inFailure = true;
            } elseif ($inFailure) {
                $currentOutput .= $line . "\n";
            }
        }

        if ($currentTest !== null) {
            $failures[] = [
                'test' => $currentTest,
                'output' => trim($currentOutput),
            ];
        }

        return $failures;
    }
}
