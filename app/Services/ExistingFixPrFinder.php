<?php

namespace App\Services;

use App\Channels\GitHub\AppService as GitHubAppService;
use App\DataTransferObjects\ExistingFixPrMatch;
use App\DataTransferObjects\PullRequestCandidate;
use App\Models\FlakyTestClaim;
use App\Models\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Answers "is a pull request already out that fixes this failing test?".
 *
 * The candidate set is fetched once per repository per scan pass and reused
 * for every failure group, so the cost is roughly one PR listing plus one
 * file listing per inspected PR, per repository, per scan.
 */
class ExistingFixPrFinder
{
    public function __construct(private readonly GitHubAppService $github) {}

    /**
     * Open pull requests plus those merged inside the scan window, newest
     * first, each with its changed file paths.
     *
     * Merged pull requests are included because the scan looks back further
     * than it runs: a fix that merged an hour ago still leaves pre-fix
     * failures inside the window, and those look like an unfixed flaky test.
     *
     * Yak's own pull requests are included too. A Linear or Slack task that
     * happened to fix the same test leaves no flaky-test claim behind, so
     * skipping the bot's own PRs would make that fix invisible.
     *
     * @return Collection<int, PullRequestCandidate>
     */
    public function forRepository(Repository $repository): Collection
    {
        $installationId = (int) config('yak.channels.github.installation_id');

        if ($installationId === 0) {
            return collect();
        }

        $limit = (int) config('yak.ci_scan.max_prs_inspected', 20);
        $windowHours = FlakyTestClaim::windowHours();

        try {
            $open = $this->github->listOpenPullRequests($installationId, $repository->github_full_name);
            $merged = $this->github->listRecentlyMergedPullRequests(
                $installationId,
                $repository->github_full_name,
                $windowHours,
                $limit,
            );
        } catch (\Throwable $e) {
            // A failed lookup must not stop the scan: the worst case is the
            // duplicate PR this check exists to prevent, which is what
            // happened before the check existed.
            Log::warning('Failed to list pull requests for existing-fix check', [
                'repo' => $repository->slug,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }

        return collect([...$open, ...$merged])
            ->filter(fn (array $pr): bool => isset($pr['number']))
            ->unique(fn (array $pr): int => (int) $pr['number'])
            ->sortByDesc(fn (array $pr): string => (string) ($pr['updated_at'] ?? ''))
            ->take($limit)
            ->map(function (array $pr) use ($installationId, $repository): ?PullRequestCandidate {
                try {
                    $files = $this->github->listPullRequestFiles(
                        $installationId,
                        $repository->github_full_name,
                        (int) $pr['number'],
                    );
                } catch (\Throwable $e) {
                    Log::warning('Failed to list files for pull request', [
                        'repo' => $repository->slug,
                        'pr' => $pr['number'],
                        'error' => $e->getMessage(),
                    ]);

                    return null;
                }

                /** @var list<string> $paths */
                $paths = array_values(array_filter(array_map(
                    fn (array $file): ?string => is_string($file['filename'] ?? null) ? $file['filename'] : null,
                    $files,
                )));

                return PullRequestCandidate::fromApi($pr, $paths);
            })
            ->filter()
            ->values();
    }

    /**
     * The first candidate that looks like it already fixes `$testClass`.
     *
     * Matching is on the full PSR-4 path tail, so a pull request editing an
     * unrelated `UserTest.php` in another namespace does not suppress a real
     * fix. The basename-only fallback is reserved for class names Pest cut
     * short, which cannot be resolved to a path.
     *
     * @param  Collection<int, PullRequestCandidate>  $candidates
     */
    public function match(Collection $candidates, string $testClass, bool $classWasTruncated = false): ?ExistingFixPrMatch
    {
        $basename = self::basename($testClass);

        if ($basename === '') {
            return null;
        }

        $pathTail = self::pathTail($testClass);
        $fileName = $basename . '.php';

        foreach ($candidates as $candidate) {
            foreach ($candidate->changedFiles as $path) {
                if ($pathTail !== null && str_ends_with(strtolower($path), strtolower($pathTail))) {
                    return new ExistingFixPrMatch($candidate, "changes {$path}");
                }

                if ($classWasTruncated && strcasecmp(basename($path), $fileName) === 0) {
                    return new ExistingFixPrMatch($candidate, "changes a file named {$fileName}");
                }
            }

            if (str_contains($candidate->title, $basename)) {
                return new ExistingFixPrMatch($candidate, "title names {$basename}");
            }

            if (str_contains($candidate->body, $basename)) {
                return new ExistingFixPrMatch($candidate, "description names {$basename}");
            }
        }

        return null;
    }

    /**
     * `Tests\Api\Integration\Common\AccuracyScoreAPITest` becomes
     * `/Api/Integration/Common/AccuracyScoreAPITest.php` -- the namespace
     * root is dropped because its directory casing is a project convention
     * (`Tests\` maps to `tests/` in this codebase and most others).
     */
    private static function pathTail(string $testClass): ?string
    {
        $segments = array_values(array_filter(explode('\\', $testClass)));

        if (count($segments) < 2) {
            return null;
        }

        array_shift($segments);

        return '/' . implode('/', $segments) . '.php';
    }

    private static function basename(string $testClass): string
    {
        $segments = array_values(array_filter(explode('\\', $testClass)));

        return $segments === [] ? '' : (string) end($segments);
    }
}
