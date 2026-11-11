<?php

namespace App\Services\Threads\PullRequests;

use App\Models\Repository;
use App\Models\ThreadRepository;
use Illuminate\Support\Str;

/**
 * The prompts for turns that start from an event on one repo's pull request.
 * Each names the repo, its path in the sandbox and its pull request so the
 * agent changes only that repo.
 */
final class RepositoryTurnPrompts
{
    private const OUTPUT_LIMIT = 6000;

    public static function ciFailure(ThreadRepository $row, ?string $output): string
    {
        $prompt = self::header($row) . "\n\nCI failed on this pull request. Fix the failure in {$row->sandbox_path} only, commit your fix; Yak pushes it.";

        if ($output !== null && trim($output) !== '') {
            $prompt .= "\n\nFailure output:\n" . Str::limit($output, self::OUTPUT_LIMIT);
        }

        return $prompt;
    }

    public static function comment(ThreadRepository $row, string $instructions, ?string $author, ?string $file = null, ?int $line = null): string
    {
        $prompt = self::header($row) . "\n\n" . ($author !== null && $author !== '' ? "{$author} commented" : 'A comment came in')
            . ' on this pull request. Change only that repo.';

        if ($file !== null && $file !== '') {
            $prompt .= "\nFile: {$file}" . ($line !== null ? ":{$line}" : '');
        }

        return $prompt . "\n\n" . $instructions;
    }

    public static function review(ThreadRepository $row, string $state, string $body, ?string $reviewer): string
    {
        $who = $reviewer !== null && $reviewer !== '' ? $reviewer : 'A reviewer';
        $prompt = self::header($row) . "\n\n{$who} submitted a review ({$state}) on this pull request. Read its inline comments on the pull request, then change only that repo.";

        return trim($body) === '' ? $prompt : $prompt . "\n\n" . $body;
    }

    public static function pullRequestReference(ThreadRepository $row): string
    {
        $name = Repository::githubNameFor($row->repo);

        return $row->pull_request_number !== null ? "{$name}#{$row->pull_request_number}" : $name;
    }

    private static function header(ThreadRepository $row): string
    {
        return 'Repository: ' . Repository::githubNameFor($row->repo) . " (checked out at {$row->sandbox_path}), pull request " . self::pullRequestReference($row) . '.';
    }
}
