<?php

namespace App\Services;

use App\Channels\GitHub\AppService;
use App\Models\Repository;
use App\Support\Docs;
use App\Support\PathMatcher;
use App\Support\YamlKeyLocator;
use Illuminate\Support\Str;

/**
 * Posts the `yak / config` check run on a pull request that changes `.yak/`.
 * It reads the files at the pull request's head commit only.
 */
class RepositoryConfigCheck
{
    private const CHECK_NAME = 'yak / config';

    private const MAX_ANNOTATIONS = 50;

    private const MAX_LISTED_PER_FILE = 30;

    /** Upper bound on glob x path comparisons, since risk-profile content comes from the pull request. */
    public const MAX_GLOB_COMPARISONS = 2000000;

    /** Wall-clock budget for the whole glob check. */
    public const GLOB_CHECK_SECONDS = 5;

    /** Tighter than PHP's default so one hostile glob cannot run long. */
    private const GLOB_BACKTRACK_LIMIT = '100000';

    public function __construct(
        private AppService $github,
        private RepositoryConfigParser $parser,
        private int $maxGlobComparisons = self::MAX_GLOB_COMPARISONS,
        private float $globCheckSeconds = self::GLOB_CHECK_SECONDS,
    ) {}

    /**
     * @return array<string, mixed>|null the posted payload, or null when the pull request touches no `.yak/` file
     */
    public function run(Repository $repository, int $pullRequestNumber, string $headSha): ?array
    {
        $installationId = (int) config('yak.channels.github.installation_id');
        $slug = $repository->github_full_name;

        $changesYak = collect($this->github->listPullRequestFilesOrFail($installationId, $slug, $pullRequestNumber))
            ->contains(fn (array $file): bool => str_starts_with((string) ($file['filename'] ?? ''), '.yak/'));

        if (! $changesYak) {
            return null;
        }

        $failures = [];
        $annotations = [];
        $errorCounts = [];
        $dataByFile = [];
        $contentByFile = [];

        $fail = function (string $file, string $text, string $annotation, int $line) use (&$failures, &$annotations): void {
            $failures[$file][] = $text;
            $annotations[] = [
                'path' => ".yak/{$file}",
                'start_line' => $line,
                'end_line' => $line,
                'annotation_level' => 'failure',
                'message' => $annotation,
            ];
        };

        foreach (RepositoryConfigParser::FILES as $file) {
            $content = $this->github->getFileContents($installationId, $slug, ".yak/{$file}", $headSha);

            if ($content === null) {
                continue;
            }

            $contentByFile[$file] = $content;
            $result = $this->parser->parse($file, $content);

            if ($result['errors'] === []) {
                $dataByFile[$file] = $result['data'];

                continue;
            }

            foreach ($result['errors'] as $error) {
                $key = Str::before($error, ': ');
                $fail($file, "`{$key}`: " . Str::after($error, ': '), $error, YamlKeyLocator::line($content, $key));
                $errorCounts[$file] = ($errorCounts[$file] ?? 0) + 1;
            }
        }

        $deadAreaCount = 0;
        $notes = [];

        if (isset($dataByFile['risk-profile.yml'])) {
            $previousBacktrackLimit = ini_set('pcre.backtrack_limit', self::GLOB_BACKTRACK_LIMIT);
            $startedAt = hrtime(true);

            try {
                $tree = $this->github->listTreePaths($installationId, $slug, $headSha);
                $content = $contentByFile['risk-profile.yml'];
                $comparisons = 0;
                $isCapped = false;

                if ($tree === null) {
                    $notes[] = 'Skipped the glob check: GitHub truncated the file list for this commit.';
                }

                foreach ($tree === null ? [] : $dataByFile['risk-profile.yml']['areas'] ?? [] as $areaIndex => $area) {
                    $isDead = false;

                    foreach ($area['paths'] ?? [] as $pathIndex => $glob) {
                        $matcher = PathMatcher::compile($glob);
                        $isMatched = false;
                        $isUnchecked = false;

                        foreach ($tree as $path) {
                            if ($comparisons++ >= $this->maxGlobComparisons || hrtime(true) - $startedAt >= $this->globCheckSeconds * 1e9) {
                                $isCapped = true;

                                break 3;
                            }

                            $result = $matcher($path);

                            if ($result === null) {
                                $isUnchecked = true;

                                break;
                            }

                            if ($result) {
                                $isMatched = true;

                                break;
                            }
                        }

                        if ($isUnchecked) {
                            $notes[] = "Could not check: `{$glob}`";
                        }

                        if ($isMatched || $isUnchecked) {
                            continue;
                        }

                        $isDead = true;
                        $line = YamlKeyLocator::line($content, "areas.{$areaIndex}.paths.{$pathIndex}");
                        $line = $line === 1 ? YamlKeyLocator::line($content, "areas.{$areaIndex}.paths") : $line;
                        $fail('risk-profile.yml', "Area **{$area['name']}**: `{$glob}` matches no file in this commit", "Area \"{$area['name']}\": {$glob} matches no file", $line);
                    }

                    $deadAreaCount += $isDead ? 1 : 0;
                }

            } finally {
                ini_set('pcre.backtrack_limit', (string) $previousBacktrackLimit);
            }

            if ($isCapped) {
                $notes[] = 'Skipped the rest of the glob check: the risk profile and file list are too large to compare.';
            }

            if ($deadAreaCount > 0) {
                unset($dataByFile['risk-profile.yml']);
            }
        }

        $isValid = $failures === [];
        $hasNoFiles = $isValid && $contentByFile === [];

        $payload = [
            'name' => self::CHECK_NAME,
            'head_sha' => $headSha,
            'status' => 'completed',
            'conclusion' => $isValid ? 'success' : 'failure',
            'output' => [
                'title' => $hasNoFiles ? 'No .yak/ files in this commit' : ($isValid ? '.yak/ is valid' : $this->title($errorCounts, $deadAreaCount)),
                'summary' => match (true) {
                    $hasNoFiles => "This pull request's head commit has no `.yak/` files, so Yak has nothing to read from it. " . $this->link(),
                    $isValid => $this->passingSummary($dataByFile, $contentByFile, $notes, $repository->default_branch),
                    default => $this->failingSummary($failures, array_keys($dataByFile), $notes),
                },
                'annotations' => array_slice($annotations, 0, self::MAX_ANNOTATIONS),
            ],
        ];

        $this->github->createCheckRun($installationId, $slug, $payload);

        return $payload;
    }

    /** @param array<string, int> $errorCounts */
    private function title(array $errorCounts, int $globFailures): string
    {
        $parts = [];

        foreach ($errorCounts as $file => $count) {
            if ($count > 0) {
                $parts[] = $count . ' ' . Str::plural('error', $count) . " in .yak/{$file}";
            }
        }

        if ($globFailures > 0) {
            $parts[] = $globFailures . ' risk ' . ($globFailures === 1 ? 'area matches' : 'areas match') . ' no file';
        }

        return implode(', ', $parts);
    }

    /**
     * @param  array<string, list<string>>  $failures
     * @param  list<string>  $passed
     * @param  list<string>  $notes
     */
    private function failingSummary(array $failures, array $passed, array $notes): string
    {
        $summary = "Yak checked the `.yak/` files on this pull request's head commit. Merging it as is would leave Yak on the last valid version of the files that fail.\n";

        foreach ($failures as $file => $items) {
            $summary .= "\n### .yak/{$file}\n\n";
            foreach (array_slice($items, 0, self::MAX_LISTED_PER_FILE) as $item) {
                $summary .= "- {$item}\n";
            }
            if (count($items) > self::MAX_LISTED_PER_FILE) {
                $summary .= '- and ' . (count($items) - self::MAX_LISTED_PER_FILE) . " more\n";
            }
        }

        if ($passed !== []) {
            $summary .= "\n### Passed\n\n";
            foreach ($passed as $file) {
                $summary .= "- `.yak/{$file}`\n";
            }
        }

        foreach ($notes as $note) {
            $summary .= "\n{$note}\n";
        }

        return $summary . "\nMake this check required in branch protection to block merges that would break Yak's config. " . $this->link();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $contents
     * @param  list<string>  $notes
     */
    private function passingSummary(array $data, array $contents, array $notes, string $defaultBranch): string
    {
        $rows = [];

        foreach ($data as $file => $value) {
            $rows[] = "| `{$file}` | " . match ($file) {
                'config.yml' => 'Valid. Co-owner gate: ' . $this->modeText($value['co_owner_gate']['mode'] ?? null) . '. Review approval: ' . $this->modeText($value['review']['approval']['mode'] ?? null) . '.',
                'preview.yml' => "Valid. Port {$value['port']}, health probe `{$value['health_probe_path']}`.",
                'risk-profile.yml' => 'Valid. ' . ($count = count($value['areas'] ?? [])) . ' ' . Str::plural('area', $count)
                    . ($notes === [] ? ', every glob matches at least one file' : '')
                    . ', ' . ($open = count($value['unknowns'] ?? [])) . ' open ' . Str::plural('question', $open) . '.',
                'AGENTS.md' => 'Valid. ' . number_format(mb_strlen($contents[$file])) . ' characters.',
                default => 'Valid.',
            } . ' |';
        }

        return "| File | Result |\n| --- | --- |\n" . implode("\n", $rows) . "\n"
            . implode('', array_map(fn (string $note): string => "\n{$note}\n", $notes))
            . "\nThese settings apply once this pull request merges into `{$defaultBranch}`. " . $this->link();
    }

    private function modeText(?string $mode): string
    {
        return $mode === null ? '**not set** (keeps its current value)' : "**{$mode}**";
    }

    private function link(): string
    {
        return '[How .yak/ works](' . Docs::url('repositories.config') . ')';
    }
}
