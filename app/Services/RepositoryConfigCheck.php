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

    public function __construct(private AppService $github, private RepositoryConfigParser $parser) {}

    /**
     * @return array<string, mixed>|null the posted payload, or null when the pull request touches no `.yak/` file
     */
    public function run(Repository $repository, int $pullRequestNumber, string $headSha): ?array
    {
        $installationId = (int) config('yak.channels.github.installation_id');
        $slug = $repository->github_full_name;

        $changesYak = collect($this->github->listPullRequestFiles($installationId, $slug, $pullRequestNumber))
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
        $treeTruncated = false;

        if (isset($dataByFile['risk-profile.yml'])) {
            $tree = $this->github->listTreePaths($installationId, $slug, $headSha);
            $treeTruncated = $tree === null;
            $content = $contentByFile['risk-profile.yml'];

            foreach ($tree === null ? [] : $dataByFile['risk-profile.yml']['areas'] ?? [] as $areaIndex => $area) {
                $isDead = false;

                foreach ($area['paths'] ?? [] as $pathIndex => $glob) {
                    if (collect($tree)->contains(fn (string $path): bool => PathMatcher::matches($path, [$glob]))) {
                        continue;
                    }

                    $isDead = true;
                    $line = YamlKeyLocator::line($content, "areas.{$areaIndex}.paths.{$pathIndex}");
                    $line = $line === 1 ? YamlKeyLocator::line($content, "areas.{$areaIndex}.paths") : $line;
                    $fail('risk-profile.yml', "Area **{$area['name']}**: `{$glob}` matches no file in this commit", "Area \"{$area['name']}\": {$glob} matches no file", $line);
                }

                $deadAreaCount += $isDead ? 1 : 0;
            }

            if ($deadAreaCount > 0) {
                unset($dataByFile['risk-profile.yml']);
            }
        }

        $isValid = $failures === [];

        $payload = [
            'name' => self::CHECK_NAME,
            'head_sha' => $headSha,
            'status' => 'completed',
            'conclusion' => $isValid ? 'success' : 'failure',
            'output' => [
                'title' => $isValid ? '.yak/ is valid' : $this->title($errorCounts, $deadAreaCount),
                'summary' => $isValid
                    ? $this->passingSummary($dataByFile, $contentByFile, $treeTruncated, $repository->default_branch)
                    : $this->failingSummary($failures, array_keys($dataByFile), $treeTruncated),
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
     */
    private function failingSummary(array $failures, array $passed, bool $treeTruncated): string
    {
        $summary = "Yak checked the `.yak/` files on this pull request's head commit. Merging it as is would leave Yak on the last valid version of the files that fail.\n";

        foreach ($failures as $file => $items) {
            $summary .= "\n### .yak/{$file}\n\n";
            foreach ($items as $item) {
                $summary .= "- {$item}\n";
            }
        }

        if ($passed !== []) {
            $summary .= "\n### Passed\n\n";
            foreach ($passed as $file) {
                $summary .= "- `.yak/{$file}`\n";
            }
        }

        if ($treeTruncated) {
            $summary .= "\n" . $this->truncatedLine() . "\n";
        }

        return $summary . "\nMake this check required in branch protection to block merges that would break Yak's config. " . $this->link();
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $contents
     */
    private function passingSummary(array $data, array $contents, bool $treeTruncated, string $defaultBranch): string
    {
        $rows = [];

        foreach ($data as $file => $value) {
            $rows[] = "| `{$file}` | " . match ($file) {
                'config.yml' => 'Valid. Co-owner gate: **' . ($value['co_owner_gate']['mode'] ?? 'off') . '**. Review approval: **' . ($value['review']['approval']['mode'] ?? 'off') . '**.',
                'preview.yml' => "Valid. Port {$value['port']}, health probe `{$value['health_probe_path']}`.",
                'risk-profile.yml' => 'Valid. ' . ($count = count($value['areas'] ?? [])) . ' ' . Str::plural('area', $count)
                    . ($treeTruncated ? '' : ', every glob matches at least one file')
                    . ', ' . ($open = count($value['unknowns'] ?? [])) . ' open ' . Str::plural('question', $open) . '.',
                'AGENTS.md' => 'Valid. ' . number_format(mb_strlen($contents[$file])) . ' characters.',
                default => 'Valid.',
            } . ' |';
        }

        return "| File | Result |\n| --- | --- |\n" . implode("\n", $rows) . "\n"
            . ($treeTruncated ? "\n" . $this->truncatedLine() . "\n" : '')
            . "\nThese settings apply once this pull request merges into `{$defaultBranch}`. " . $this->link();
    }

    private function truncatedLine(): string
    {
        return 'Skipped the glob check: GitHub truncated the file list for this commit.';
    }

    private function link(): string
    {
        return '[How .yak/ works](' . Docs::url('repositories.config') . ')';
    }
}
