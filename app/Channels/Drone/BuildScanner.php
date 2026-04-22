<?php

namespace App\Channels\Drone;

use App\Channels\Contracts\CIBuildScanner;
use App\DataTransferObjects\BuildResult;
use App\DataTransferObjects\CIBuildFailure;
use App\Models\Repository;
use Carbon\Carbon;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class BuildScanner implements CIBuildScanner
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
        /** @var array<int, array{number: int, status: string, source?: string|null, started: int, link: string, after?: string|null}> $builds */
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
                    branch: $build['source'] ?? null,
                    commitSha: $build['after'] ?? null,
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

        /** @var array<int, array{number: int, status: string, started: int, link: string, after?: string|null}> $builds */
        $builds = Http::withToken($droneToken)
            ->get("{$droneUrl}/api/repos/{$repository->slug}/builds", ['branch' => $branch])
            ->json() ?? [];

        // 60s grace: Drone can lag a few seconds behind the git push.
        $cutoff = $notBefore->getTimestamp() - 60;

        $build = collect($builds)
            ->filter(fn (array $b) => $b['started'] >= $cutoff)
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

                /** @var array<int, array{out?: string|null}> $stepLog */
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
     * Parses Pest's `FAILED` markers from a build log. Only lines matching
     * the `Class\\Path > it does something` shape are accepted — bare
     * `FAILED` banners (e.g. build summaries, parallel runner stats) are
     * ignored so we don't emit blank test names.
     *
     * @return array<int, array{test: string, output: string}>
     */
    private function parseTestFailures(string $output): array
    {
        // Drone interleaves ANSI colour codes; strip them so the regex
        // doesn't match "FAILED<esc>[0m" and capture garbage.
        $output = preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $output) ?? $output;

        $failures = [];
        $lines = explode("\n", $output);

        /** @var string|null $currentTest */
        $currentTest = null;
        $currentOutput = '';

        $flush = function () use (&$failures, &$currentTest, &$currentOutput): void {
            if ($currentTest !== null) {
                $failures[] = [
                    'test' => $currentTest,
                    'output' => trim($currentOutput),
                ];
            }
            $currentTest = null;
            $currentOutput = '';
        };

        foreach ($lines as $line) {
            // Pest's FAILED header: `   FAILED  Tests\Foo > it does something`.
            // Require the ` > ` separator so bare `FAILED` banners don't count.
            if (preg_match('/^\s*FAILED\s+(\S.*?\s>\s.+?)\s*$/', $line, $matches)) {
                $flush();
                $currentTest = trim($matches[1]);
                $currentOutput = $line . "\n";

                continue;
            }

            // Pest summary line ends the failure block:
            //   `Tests:    1 failed, 8 skipped, 64 passed (225 assertions)`
            if (preg_match('/^\s*Tests:\s+\d+\s+failed/', $line)) {
                $flush();

                continue;
            }

            if ($currentTest !== null) {
                $currentOutput .= $line . "\n";
            }
        }

        $flush();

        return $failures;
    }
}
