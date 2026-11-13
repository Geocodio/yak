<?php

namespace App\Jobs;

use App\Enums\RepositoryRole;
use App\Enums\TeleportState;
use App\Enums\TeleportStep;
use App\Enums\ThreadSandboxState;
use App\Exceptions\TeleportError;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Teleport\TeleportEnvironmentNote;
use App\Services\Teleport\TeleportExtras;
use App\Services\Teleport\Teleports;
use App\Services\Teleport\TeleportUpCompleter;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\EnvironmentNote;
use App\Services\Threads\RefreshTriggers;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Carbon;
use RuntimeException;
use Throwable;

/**
 * Teleport up, steps d to f: wake the sandbox, bring it to the pushed head, restore the session, move ownership.
 * A failure before the ownership move marks the teleport failed and leaves the owner alone.
 */
class PrepareTeleportUpJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /** Unlimited releases: the job re-queues itself until the sandbox is awake or the slot wait runs out. */
    public int $tries = 0;

    public function __construct(public string $teleportId) {}

    public function handle(
        ThreadSandbox $sandbox,
        SandboxManager $sandboxes,
        SessionFileSync $sessions,
        RefreshTriggers $refresh,
        EnvironmentNote $note,
        TeleportUpCompleter $completer,
        Teleports $teleports,
        TeleportExtras $extras,
    ): void {
        $teleport = Teleport::query()->find($this->teleportId);

        if ($teleport === null || $teleport->state !== TeleportState::Preparing) {
            return;
        }

        $thread = Thread::query()->withoutGlobalScopes()->findOrFail($teleport->thread_id);

        try {
            $sandboxState = $this->prepare($teleport, $thread, $sandbox, $sandboxes, $sessions, $refresh, $note, $extras);

            if ($sandboxState === null) {
                if ($this->slotWaitExpired($teleport)) {
                    $teleports->fail($teleport->refresh(), 'no_sandbox_slot', 'Yak has no free sandbox right now. Your work is safe on Yak; run yak teleport again later.');

                    return;
                }

                $this->release((int) config('yak.teleport.slot_poll_seconds', 5));

                return;
            }

            $completer->finish($teleport->refresh(), $sandboxState);
        } catch (Throwable $exception) {
            $teleports->fail($teleport->refresh(), 'teleport_failed', $exception instanceof TeleportError || $exception instanceof RuntimeException ? $exception->getMessage() : 'Preparing the sandbox failed.');
            report($exception);
        }
    }

    private function prepare(
        Teleport $teleport,
        Thread $thread,
        ThreadSandbox $sandbox,
        SandboxManager $sandboxes,
        SessionFileSync $sessions,
        RefreshTriggers $refresh,
        EnvironmentNote $note,
        TeleportExtras $extras,
    ): ?string {
        $wasRestored = in_array($thread->sandbox_state, [ThreadSandboxState::None, ThreadSandboxState::Destroying, ThreadSandboxState::Destroyed], true);

        $teleport->progressStep(TeleportStep::Restore, 'running');
        $wake = $sandbox->ensureAwake($thread);

        if ($wake->waitingForSlot || $wake->restoring) {
            $teleport->touchCall();
            $teleport->forceFill(['payload' => [...($teleport->payload ?? []), 'waiting_for_slot' => $wake->waitingForSlot]])->save();

            return null;
        }

        if (! $wake->ready) {
            $teleport->progressStep(TeleportStep::Restore, 'failed');

            throw new RuntimeException('Restoring the sandbox failed.');
        }

        $teleport->progressStep(TeleportStep::Restore, 'done');
        $thread->refresh();

        if ($wasRestored) {
            // The restore already checked out the branch, ran setup and refresh, and restored the session.
            $this->prepareExtras($teleport, $thread, $extras);
            $this->writeNote($teleport, $thread, $note);

            foreach ([TeleportStep::FetchBranch, TeleportStep::Setup, TeleportStep::Refresh, TeleportStep::SessionRestore] as $step) {
                $teleport->progressStep($step, 'done');
            }

            return 'ready';
        }

        $this->fetchBranches($teleport, $thread, $sandboxes);
        $this->prepareExtras($teleport, $thread, $extras);
        $this->runSetup($teleport, $thread, $sandboxes, $refresh);
        $this->runRefresh($teleport, $thread, $refresh, $sandboxes);

        $teleport->progressStep(TeleportStep::SessionRestore, 'running');
        $sessions->restoreIntoSandbox($thread);
        $this->linkLocalPath($thread, $teleport, $sandboxes);
        $this->writeNote($teleport, $thread, $note);
        $teleport->progressStep(TeleportStep::SessionRestore, 'done');

        return 'ready';
    }

    /**
     * True once the teleport has waited longer than the slot wait. A missing start time is written now so the clock always runs.
     */
    private function slotWaitExpired(Teleport $teleport): bool
    {
        $startedAt = $teleport->payload['prepare_started_at'] ?? null;

        if ($startedAt === null) {
            $teleport->forceFill(['payload' => [...($teleport->payload ?? []), 'prepare_started_at' => now()->toIso8601String()]])->save();

            return false;
        }

        return Carbon::parse($startedAt)->diffInMinutes(now(), true) > (int) config('yak.teleport.slot_wait_minutes');
    }

    private function fetchBranches(Teleport $teleport, Thread $thread, SandboxManager $sandboxes): void
    {
        $teleport->progressStep(TeleportStep::FetchBranch, 'running');

        foreach ($thread->repositories->where('role', RepositoryRole::Primary) as $repository) {
            $head = $teleport->branch_heads[$repository->repo] ?? $this->headFor($teleport, $repository->repo);

            if ($head === null || blank($repository->branch)) {
                continue;
            }

            foreach ([
                ['git', '-C', $repository->sandbox_path, 'fetch', 'origin', $repository->branch],
                ['git', '-C', $repository->sandbox_path, 'checkout', '-B', $repository->branch],
                ['git', '-C', $repository->sandbox_path, 'reset', '--hard', $head],
            ] as $command) {
                $result = $sandboxes->exec($thread, $command);

                if ($result->failed()) {
                    $teleport->progressStep(TeleportStep::FetchBranch, 'failed');

                    throw new RuntimeException("Bringing {$repository->repo} to the pushed branch failed.");
                }
            }
        }

        $teleport->progressStep(TeleportStep::FetchBranch, 'done');
    }

    private function prepareExtras(Teleport $teleport, Thread $thread, TeleportExtras $extras): void
    {
        try {
            $report = $extras->prepare($thread, $teleport);
        } catch (RuntimeException $exception) {
            $teleport->progressStep(TeleportStep::FetchBranch, 'failed');

            throw $exception;
        }

        $teleport->forceFill(['payload' => [...($teleport->payload ?? []), 'sandbox_repos' => $report]])->save();
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

    private function runSetup(Teleport $teleport, Thread $thread, SandboxManager $sandboxes, RefreshTriggers $refresh): void
    {
        $teleport->progressStep(TeleportStep::Setup, 'running');

        if ($sandboxes->exec($thread, ['test', '-f', '.yak/setup.sh'])->successful()) {
            $result = $sandboxes->exec($thread, ['bash', '.yak/setup.sh'], env: $refresh->buildEnvironment($thread));

            if ($result->failed()) {
                $teleport->progressStep(TeleportStep::Setup, 'failed');

                throw new RuntimeException('The setup script failed.');
            }
        }

        foreach ($this->changesExtras($thread) as $repository) {
            $setupScript = "{$repository->sandbox_path}/.yak/setup.sh";

            if ($sandboxes->exec($thread, ['test', '-f', $setupScript])->successful()
                && $sandboxes->exec($thread, ['bash', $setupScript], env: $refresh->buildEnvironment($thread))->failed()) {
                $teleport->progressStep(TeleportStep::Setup, 'failed');

                throw new RuntimeException("The setup script of {$repository->repo} failed.");
            }
        }

        $teleport->progressStep(TeleportStep::Setup, 'done');
    }

    /**
     * @return Collection<int, ThreadRepository>
     */
    private function changesExtras(Thread $thread): Collection
    {
        return $thread->repositories()->where('role', RepositoryRole::Changes->value)->get();
    }

    /**
     * Runs the commands each changes extra's own refresh map calls for.
     *
     * @return list<string>
     */
    private function refreshExtras(Thread $thread, SandboxManager $sandboxes, RefreshTriggers $refresh): array
    {
        $ran = [];

        foreach ($this->changesExtras($thread) as $repository) {
            $map = $refresh->configFor($thread, $repository->sandbox_path);

            if ($map === []) {
                continue;
            }

            $listing = $sandboxes->exec($thread, ['git', '-C', $repository->sandbox_path, 'ls-files'])->output();
            $files = array_values(array_filter(array_map('trim', explode("\n", $listing))));

            foreach ($refresh->matching($map, $files) as $command) {
                $result = $sandboxes->exec($thread, ['sh', '-c', "cd {$repository->sandbox_path} && {$command}"], env: $refresh->buildEnvironment($thread));
                $ran[] = "{$repository->repo}: {$command}";

                if ($result->failed()) {
                    throw new RuntimeException("Refresh command failed in {$repository->repo}: {$command}");
                }
            }
        }

        return $ran;
    }

    private function runRefresh(Teleport $teleport, Thread $thread, RefreshTriggers $refresh, SandboxManager $sandboxes): void
    {
        $teleport->progressStep(TeleportStep::Refresh, 'running');
        $results = $refresh->run($thread);
        $ran = array_map(fn ($result): string => $result->command, $results);
        $last = end($results);

        if ($last === false || $last->succeeded()) {
            try {
                $ran = [...$ran, ...$this->refreshExtras($thread, $sandboxes, $refresh)];
            } catch (RuntimeException $exception) {
                $teleport->progressStep(TeleportStep::Refresh, 'failed');

                throw $exception;
            }
        }

        $teleport->forceFill(['payload' => [...($teleport->payload ?? []), 'refresh_ran' => $ran]])->save();

        if ($last !== false && ! $last->succeeded()) {
            $teleport->progressStep(TeleportStep::Refresh, 'failed');

            throw new RuntimeException("Refresh command failed: {$last->command}");
        }

        $teleport->progressStep(TeleportStep::Refresh, 'done');
    }

    private function linkLocalPath(Thread $thread, Teleport $teleport, SandboxManager $sandboxes): void
    {
        $workspace = (string) config('yak.sandbox.workspace_path', '/workspace');
        $links = [];

        if (filled($thread->local_repo_path)) {
            $links[(string) $thread->local_repo_path] = $workspace;
        }

        foreach ($this->changesExtras($thread) as $repository) {
            $localPath = $teleport->localPathOf($repository->repo);

            if ($localPath !== null) {
                $links[$localPath] = $repository->sandbox_path;
            }
        }

        foreach ($links as $localPath => $target) {
            $sandboxes->exec($thread, ['sh', '-c', SandboxManager::LINK_LOCAL_PATH_SCRIPT, 'sh', $localPath, $target]);
        }
    }

    private function writeNote(Teleport $teleport, Thread $thread, EnvironmentNote $note): void
    {
        $note->write($thread, app(TeleportEnvironmentNote::class)->forUp($thread, $teleport->refresh()));
    }

    public function failed(Throwable $exception): void
    {
        $teleport = Teleport::query()->find($this->teleportId);

        if ($teleport !== null) {
            app(Teleports::class)->fail($teleport, 'teleport_failed', 'Preparing the sandbox failed.');
        }
    }
}
