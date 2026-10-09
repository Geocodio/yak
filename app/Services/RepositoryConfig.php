<?php

namespace App\Services;

use App\Channels\GitHub\AppService;
use App\DataTransferObjects\ConfigFile;
use App\DataTransferObjects\ConfigSnapshot;
use App\Models\Repository;
use App\Models\RepositoryConfigFile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reads a repository's `.yak/` files from its default branch.
 *
 * The default branch head SHA is cached for five minutes, and a push to the
 * default branch clears it. Files are fetched again only when that SHA moves.
 * Each file's last valid version is stored, so a broken file, a GitHub outage
 * or a cache flush never falls back to defaults. Config is never read from a
 * task branch, so a change cannot edit the rules that judge it.
 *
 * A failed read pauses further GitHub calls for a minute, and `forget()`
 * lifts the pause.
 *
 * Two workers can read the same new SHA at once. Each writes inside a
 * transaction, and the one that loses the race on a file's unique index
 * defers to the winner and reloads the repository. No lock is used; add a
 * `Cache::lock` around `read()` if GitHub rate limits become a problem.
 */
class RepositoryConfig
{
    public function __construct(
        private AppService $github,
        private RepositoryConfigParser $parser,
    ) {}

    public static function headCacheKey(Repository $repository): string
    {
        return "yak-config:{$repository->id}:head";
    }

    private static function backoffCacheKey(Repository $repository): string
    {
        return "yak-config:{$repository->id}:backoff";
    }

    public function forget(Repository $repository): void
    {
        Cache::forget(self::headCacheKey($repository));
        Cache::forget(self::backoffCacheKey($repository));
    }

    public function snapshot(Repository $repository): ConfigSnapshot
    {
        $installationId = (int) config('yak.channels.github.installation_id');

        if ($installationId === 0) {
            return ConfigSnapshot::unavailable();
        }

        if (Cache::has(self::backoffCacheKey($repository))) {
            return $this->fromStorage($repository);
        }

        try {
            $headSha = $this->headSha($repository, $installationId);

            if ($headSha === null) {
                $this->recordReadError($repository, 'Could not read the default branch from GitHub.');
            } elseif ($headSha !== $repository->config_commit_sha) {
                $this->read($repository, $installationId, $headSha);
            } elseif ($repository->config_read_error !== null) {
                $repository->forceFill(['config_read_error' => null])->saveQuietly();
            }
        } catch (\Throwable $exception) {
            report($exception);
            Cache::put(self::backoffCacheKey($repository), true, now()->addMinute());
            $repository->refresh();
            $this->recordReadError($repository, $exception->getMessage());
        }

        return $this->fromStorage($repository);
    }

    /**
     * A failed lookup is cached for a minute, so an outage does not make every
     * caller wait on GitHub.
     */
    private function headSha(Repository $repository, int $installationId): ?string
    {
        $key = self::headCacheKey($repository);
        $cached = Cache::get($key);

        if (is_string($cached) || $cached === false) {
            return $cached ?: null;
        }

        try {
            $sha = $this->github->getBranchHeadSha($installationId, $repository->github_full_name, $repository->default_branch);
        } catch (\Throwable $exception) {
            Cache::put($key, false, now()->addMinute());

            throw $exception;
        }

        Cache::put($key, $sha ?? false, $sha === null ? now()->addMinute() : now()->addMinutes(5));

        return $sha;
    }

    /**
     * Fetches every file before writing anything, so a failure part-way
     * leaves the stored versions untouched.
     */
    private function read(Repository $repository, int $installationId, string $sha): void
    {
        $contents = [];
        foreach (RepositoryConfigParser::FILES as $name) {
            $contents[$name] = $this->github->getFileContents($installationId, $repository->github_full_name, ".yak/{$name}", $sha);
        }

        try {
            DB::transaction(function () use ($repository, $installationId, $sha, $contents): void {
                $this->store($repository, $installationId, $sha, $contents);
            });
        } catch (UniqueConstraintViolationException) {
            $repository->refresh();
        }
    }

    /** @param  array<string, string|null>  $contents */
    private function store(Repository $repository, int $installationId, string $sha, array $contents): void
    {
        foreach ($contents as $name => $content) {
            if ($content === null) {
                RepositoryConfigFile::where('repository_id', $repository->id)->where('name', $name)->delete();

                continue;
            }

            $parsed = $this->parser->parse($name, $content);
            $row = RepositoryConfigFile::firstOrNew(['repository_id' => $repository->id, 'name' => $name]);

            if ($parsed['errors'] === []) {
                $isUnchanged = $row->exists && $row->error === null && $row->content === $content;

                if (! $isUnchanged) {
                    $row->fill(['content' => $content, 'data' => $parsed['data'], 'valid_commit_sha' => $sha,
                        'error' => null, 'error_commit_sha' => null, 'error_pull_request' => null]);
                }

                if ($name === 'config.yml' && ($parsed['data']['co_owner_gate']['mode'] ?? null) === 'enforce') {
                    $repository->co_owner_gate_enforced_at ??= now();
                }
            } else {
                $error = implode("\n", $parsed['errors']);

                if ($row->error !== $error) {
                    $row->error_commit_sha = $sha;
                    $row->error_pull_request = $this->github->findPullRequestForCommit($installationId, $repository->github_full_name, $sha);
                }
                $row->error = $error;
            }

            $row->save();
        }

        $repository->forceFill(['config_commit_sha' => $sha, 'config_read_at' => now(), 'config_read_error' => null])->saveQuietly();
    }

    private function recordReadError(Repository $repository, string $message): void
    {
        if ($repository->config_read_error !== $message) {
            $repository->forceFill(['config_read_error' => mb_substr($message, 0, 500)])->saveQuietly();
        }
    }

    private function fromStorage(Repository $repository): ConfigSnapshot
    {
        $files = RepositoryConfigFile::where('repository_id', $repository->id)->get()
            ->mapWithKeys(fn (RepositoryConfigFile $row): array => [$row->name => new ConfigFile(
                $row->name, $row->data, $row->content, $row->valid_commit_sha,
                $row->error, $row->error_commit_sha, $row->error_pull_request,
            )])
            ->all();

        return new ConfigSnapshot(
            $repository->config_read_error === null ? 'read' : 'unreachable',
            $repository->config_commit_sha,
            $repository->config_read_at?->toImmutable(),
            $repository->config_read_error,
            $files,
        );
    }
}
