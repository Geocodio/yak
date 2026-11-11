<?php

namespace App\Services\Teleport;

use App\Enums\RepositoryRole;
use App\Enums\RepositorySetupStatus;
use App\Events\ThreadRepositoryChanged;
use App\Models\Repository;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Repos\ThreadGitHubCredentials;
use RuntimeException;

/**
 * Brings the added repositories of a teleport up into the sandbox: changes repos are reset to the pushed head,
 * context repos are cloned at the default branch and left alone.
 */
class TeleportExtras
{
    public function __construct(private readonly SandboxManager $sandboxes, private readonly ThreadGitHubCredentials $credentials) {}

    /**
     * Clones the repository when the folder holds no checkout yet.
     */
    public function ensureCloned(Thread $thread, ThreadRepository $repository, string $cloneUrl): void
    {
        if ($this->sandboxes->exec($thread, ['test', '-d', $repository->sandbox_path . '/.git'])->successful()) {
            return;
        }

        if ($this->sandboxes->exec($thread, ['git', 'clone', $cloneUrl, $repository->sandbox_path])->failed()) {
            throw new RuntimeException("Cloning {$repository->repo} failed.");
        }
    }

    /**
     * @return array<string, array{status: 'ready'|'failed', reason: ?string}> keyed by repo slug
     */
    public function prepare(Thread $thread, Teleport $teleport): array
    {
        $requested = array_map(fn (array $entry): string => strtolower((string) $entry['repo']), $teleport->payload['repos'] ?? []);
        $report = [];

        $repositories = $thread->repositories()->where('role', '!=', RepositoryRole::Primary->value)->get()
            ->filter(fn (ThreadRepository $repository): bool => in_array(strtolower($repository->repo), $requested, true));

        if ($repositories->isNotEmpty()) {
            $this->credentials->refresh($thread);
        }

        foreach ($repositories as $repository) {
            $cloneUrl = (string) Repository::query()->where('slug', $repository->repo)->value('git_url');

            if ($repository->role === RepositoryRole::Context) {
                $report[$repository->repo] = $this->prepareContext($thread, $repository, $cloneUrl);
                $this->markSetup($thread, $repository, $report[$repository->repo]);

                continue;
            }

            $this->ensureCloned($thread, $repository, $cloneUrl);
            $this->resetToHead($thread, $repository, $this->headFor($teleport, $repository->repo));
            $report[$repository->repo] = ['status' => 'ready', 'reason' => null];
            $this->markSetup($thread, $repository, $report[$repository->repo]);
        }

        return $report;
    }

    /**
     * @return array{status: 'ready'|'failed', reason: ?string}
     */
    private function prepareContext(Thread $thread, ThreadRepository $repository, string $cloneUrl): array
    {
        try {
            $this->ensureCloned($thread, $repository, $cloneUrl);
        } catch (RuntimeException $exception) {
            return ['status' => 'failed', 'reason' => $exception->getMessage()];
        }

        return ['status' => 'ready', 'reason' => null];
    }

    /**
     * A row added during the teleport leaves Pending once its clone is known; a row the restore already settled is kept.
     *
     * @param  array{status: 'ready'|'failed', reason: ?string}  $outcome
     */
    private function markSetup(Thread $thread, ThreadRepository $repository, array $outcome): void
    {
        if ($repository->setup_status !== RepositorySetupStatus::Pending) {
            return;
        }

        $repository->update($outcome['status'] === 'ready'
            ? ['setup_status' => RepositorySetupStatus::Ready]
            : ['setup_status' => RepositorySetupStatus::Failed, 'setup_error' => $outcome['reason']]);

        ThreadRepositoryChanged::dispatch($thread, $repository);
    }

    private function resetToHead(Thread $thread, ThreadRepository $repository, ?string $head): void
    {
        if ($head === null || blank($repository->branch)) {
            return;
        }

        foreach ([
            ['git', '-C', $repository->sandbox_path, 'fetch', 'origin', $repository->branch],
            ['git', '-C', $repository->sandbox_path, 'checkout', '-B', $repository->branch],
            ['git', '-C', $repository->sandbox_path, 'reset', '--hard', $head],
        ] as $command) {
            if ($this->sandboxes->exec($thread, $command)->failed()) {
                throw new RuntimeException("Bringing {$repository->repo} to the pushed branch failed.");
            }
        }
    }

    private function headFor(Teleport $teleport, string $repo): ?string
    {
        foreach ($teleport->branch_heads ?? [] as $name => $head) {
            if (strtolower($name) === strtolower($repo)) {
                return $head;
            }
        }

        return null;
    }
}
