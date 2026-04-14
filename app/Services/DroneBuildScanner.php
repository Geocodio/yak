<?php

namespace App\Services;

use App\Contracts\CIBuildScanner;
use App\DataTransferObjects\CIBuildFailure;
use App\Models\Repository;
use Carbon\Carbon;
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
