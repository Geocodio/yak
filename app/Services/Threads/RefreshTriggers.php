<?php

namespace App\Services\Threads;

use App\DataTransferObjects\RefreshResult;
use App\Models\Repository;
use App\Models\Thread;
use App\Services\Threads\Contracts\SandboxManager;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Runs the `refresh:` commands of a repository's .yak/config.yml when the
 * files they watch changed since the last refresh. Build credentials reach
 * these commands through the exec environment and nothing else.
 */
class RefreshTriggers
{
    public function __construct(private SandboxManager $sandboxes) {}

    /**
     * @return array<string, string> Glob to command.
     */
    public function configFor(Thread $thread): array
    {
        $result = $this->sandboxes->exec($thread, ['cat', '.yak/config.yml']);

        if ($result->failed()) {
            return [];
        }

        return $this->parseRefreshMap($result->output(), $thread->id);
    }

    /**
     * @return array<string, string> Glob to command; empty when the YAML or the map is invalid.
     */
    public function parseRefreshMap(string $yaml, int $threadId): array
    {
        try {
            $parsed = Yaml::parse($yaml);
        } catch (ParseException $exception) {
            Log::warning('Invalid .yak/config.yml refresh map', ['thread' => $threadId, 'error' => $exception->getMessage()]);

            return [];
        }

        $map = is_array($parsed) ? ($parsed['refresh'] ?? []) : [];

        if (! is_array($map) || array_filter($map, fn (mixed $command, mixed $glob): bool => ! is_string($glob) || ! is_string($command), ARRAY_FILTER_USE_BOTH) !== []) {
            Log::warning('Invalid .yak/config.yml refresh map', ['thread' => $threadId]);

            return [];
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $refreshMap
     * @param  list<string>  $changedFiles
     * @return list<string> Each matching command once, in map order.
     */
    public function matching(array $refreshMap, array $changedFiles): array
    {
        $commands = [];

        foreach ($refreshMap as $glob => $command) {
            $pattern = $this->toRegex((string) $glob);

            foreach ($changedFiles as $file) {
                if (preg_match($pattern, $file) === 1) {
                    $commands[$command] = true;

                    break;
                }
            }
        }

        return array_map('strval', array_keys($commands));
    }

    /**
     * Runs the commands the changed files call for; the last result is the failing one when the chain stopped.
     *
     * @return list<RefreshResult>
     */
    public function run(Thread $thread): array
    {
        $map = $this->configFor($thread);

        if ($map === []) {
            return [];
        }

        $environment = $this->buildEnvironment($thread);
        $results = [];

        foreach ($this->matching($map, $this->changedFiles($thread)) as $command) {
            $result = $this->sandboxes->exec($thread, ['sh', '-c', $command], env: $environment);
            $results[] = new RefreshResult($command, (int) $result->exitCode(), $result->output() . $result->errorOutput());

            if ($result->failed()) {
                return $results;
            }
        }

        $head = trim($this->sandboxes->exec($thread, ['git', 'rev-parse', 'HEAD'])->output());

        if ($head !== '') {
            $thread->forceFill(['last_refresh_commit' => $head])->save();
        }

        return $results;
    }

    /** Only commands that appear in the thread's refresh map may run. */
    public function runRequested(Thread $thread, string $command): ProcessResult
    {
        if (! in_array($command, array_values($this->configFor($thread)), true)) {
            throw new InvalidArgumentException('That command is not listed under refresh: in .yak/config.yml.');
        }

        return $this->sandboxes->exec($thread, ['sh', '-c', $command], env: $this->buildEnvironment($thread));
    }

    /**
     * @return list<string>
     */
    private function changedFiles(Thread $thread): array
    {
        if ($thread->last_refresh_commit !== null) {
            $diff = $this->sandboxes->exec($thread, ['git', 'diff', '--name-only', $thread->last_refresh_commit, 'HEAD']);

            if ($diff->successful()) {
                return $this->lines($diff->output());
            }
        }

        return $this->lines($this->sandboxes->exec($thread, ['git', 'ls-files'])->output());
    }

    /**
     * @return list<string>
     */
    private function lines(string $output): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $output)), fn (string $line): bool => $line !== ''));
    }

    /**
     * The repository's build credentials, for exec environments only.
     *
     * @return array<string, string>
     */
    public function buildEnvironment(Thread $thread): array
    {
        $repository = Repository::where('slug', $thread->repo)->first();

        return $repository === null ? [] : array_map('strval', $repository->build_credentials ?? []);
    }

    private function toRegex(string $glob): string
    {
        $regex = '';

        for ($index = 0, $length = strlen($glob); $index < $length; $index++) {
            $character = $glob[$index];

            if ($character === '*' && ($glob[$index + 1] ?? '') === '*') {
                $isDirectoryGlob = ($glob[$index + 2] ?? '') === '/';
                $regex .= $isDirectoryGlob ? '(?:.*/)?' : '.*';
                $index += $isDirectoryGlob ? 2 : 1;
            } elseif ($character === '*') {
                $regex .= '[^/]*';
            } elseif ($character === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($character, '#');
            }
        }

        return "#^{$regex}$#";
    }
}
