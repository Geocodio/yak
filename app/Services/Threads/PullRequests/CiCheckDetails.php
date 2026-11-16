<?php

namespace App\Services\Threads\PullRequests;

use App\Channels\GitHub\AppService;
use Illuminate\Support\Str;

/**
 * The per-check details stored on a thread repository row: each check's name,
 * state and, for a failing one, a short excerpt of its output.
 */
class CiCheckDetails
{
    private const EXCERPT_LIMIT = 500;

    /**
     * @param  list<array{name: string, conclusion: ?string, durationSeconds: ?int}>  $runs
     * @return list<array{name: string, state: 'passing'|'failing'|'running', durationSeconds: ?int, excerpt: ?string}>
     */
    public static function build(array $runs, ?string $failureOutput): array
    {
        $excerpts = self::excerpts($failureOutput);
        $checks = [];

        foreach ($runs as $run) {
            $state = match ($run['conclusion']) {
                null => 'running',
                'success', 'neutral', 'skipped' => 'passing',
                default => 'failing',
            };

            $checks[$run['name']] = [
                'name' => $run['name'],
                'state' => $state,
                'durationSeconds' => $run['durationSeconds'],
                'excerpt' => $state === 'failing' ? ($excerpts[$run['name']] ?? null) : null,
            ];
        }

        foreach ($excerpts as $name => $excerpt) {
            $checks[$name] ??= ['name' => $name, 'state' => 'failing', 'durationSeconds' => null, 'excerpt' => $excerpt];
        }

        return array_values($checks);
    }

    /**
     * Fetches the check runs of a commit and builds the details. Empty when
     * GitHub cannot be reached; the failure output alone still names the
     * failing checks.
     *
     * @return list<array{name: string, state: 'passing'|'failing'|'running', durationSeconds: ?int, excerpt: ?string}>
     */
    public static function collect(AppService $github, string $repoSlug, string $commitSha, ?string $failureOutput): array
    {
        $installationId = (int) config('yak.channels.github.installation_id');
        $runs = $installationId > 0 && $commitSha !== '' ? $github->getCheckRunSummaries($installationId, $repoSlug, $commitSha) : [];

        return self::build($runs, $failureOutput);
    }

    /**
     * Parses the `## <check name> (<url>)` sections of the failure output.
     *
     * @return array<string, string>
     */
    private static function excerpts(?string $failureOutput): array
    {
        if ($failureOutput === null || trim($failureOutput) === '') {
            return [];
        }

        $excerpts = [];

        foreach (preg_split('/\n\n---\n\n/', $failureOutput) ?: [] as $section) {
            if (! preg_match('/^## (.+?) \(\S*\)\s*\n?(.*)$/s', trim($section), $match)) {
                continue;
            }

            $excerpts[$match[1]] = Str::limit(trim($match[2]), self::EXCERPT_LIMIT, '');
        }

        return $excerpts;
    }
}
