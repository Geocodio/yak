<?php

namespace App\Jobs;

use App\Enums\TeleportState;
use App\Enums\TeleportStep;
use App\Enums\ThreadSandboxState;
use App\Exceptions\TeleportError;
use App\Models\Teleport;
use App\Models\Thread;
use App\Services\Teleport\Teleports;
use App\Services\Teleport\TeleportUpCompleter;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\EnvironmentNote;
use App\Services\Threads\RefreshTriggers;
use App\Services\Threads\ThreadSandbox;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

/**
 * Teleport up, steps d to f: wake the sandbox, bring it to the pushed head, restore the session, move ownership.
 * A failure before the ownership move marks the teleport failed and leaves the owner alone.
 */
class PrepareTeleportUpJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public string $teleportId) {}

    public function handle(
        ThreadSandbox $sandbox,
        SandboxManager $sandboxes,
        SessionFileSync $sessions,
        RefreshTriggers $refresh,
        EnvironmentNote $note,
        TeleportUpCompleter $completer,
        Teleports $teleports,
    ): void {
        $teleport = Teleport::query()->find($this->teleportId);

        if ($teleport === null || $teleport->state !== TeleportState::Preparing) {
            return;
        }

        $thread = Thread::query()->withoutGlobalScopes()->findOrFail($teleport->thread_id);

        try {
            $sandboxState = $this->prepare($teleport, $thread, $sandbox, $sandboxes, $sessions, $refresh, $note);
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
    ): string {
        $wasRestored = in_array($thread->sandbox_state, [ThreadSandboxState::None, ThreadSandboxState::Destroying, ThreadSandboxState::Destroyed], true);

        $teleport->progressStep(TeleportStep::Restore, 'running');
        $wake = $sandbox->ensureAwake($thread);

        if ($wake->waitingForSlot || $wake->restoring) {
            $teleport->progressStep(TeleportStep::Restore, 'done');

            return $wake->waitingForSlot ? 'waiting_for_slot' : 'restoring';
        }

        if (! $wake->ready) {
            $teleport->progressStep(TeleportStep::Restore, 'failed');

            throw new RuntimeException('Restoring the sandbox failed.');
        }

        $teleport->progressStep(TeleportStep::Restore, 'done');
        $thread->refresh();

        if ($wasRestored) {
            // The restore already checked out the branch, ran setup and refresh, and restored the session.
            foreach ([TeleportStep::FetchBranch, TeleportStep::Setup, TeleportStep::Refresh, TeleportStep::SessionRestore] as $step) {
                $teleport->progressStep($step, 'done');
            }

            return 'ready';
        }

        $this->fetchBranches($teleport, $thread, $sandboxes);
        $this->runSetup($teleport, $thread, $sandboxes, $refresh);
        $this->runRefresh($teleport, $thread, $refresh);

        $teleport->progressStep(TeleportStep::SessionRestore, 'running');
        $sessions->restoreIntoSandbox($thread);
        $this->linkLocalPath($thread, $sandboxes);
        $this->writeNote($teleport, $thread, $note);
        $teleport->progressStep(TeleportStep::SessionRestore, 'done');

        return 'ready';
    }

    private function fetchBranches(Teleport $teleport, Thread $thread, SandboxManager $sandboxes): void
    {
        $teleport->progressStep(TeleportStep::FetchBranch, 'running');

        foreach ($thread->repositories as $repository) {
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

        $teleport->progressStep(TeleportStep::Setup, 'done');
    }

    private function runRefresh(Teleport $teleport, Thread $thread, RefreshTriggers $refresh): void
    {
        $teleport->progressStep(TeleportStep::Refresh, 'running');
        $results = $refresh->run($thread);
        $last = end($results);

        if ($last !== false && ! $last->succeeded()) {
            $teleport->progressStep(TeleportStep::Refresh, 'failed');

            throw new RuntimeException("Refresh command failed: {$last->command}");
        }

        $teleport->progressStep(TeleportStep::Refresh, 'done');
    }

    private function linkLocalPath(Thread $thread, SandboxManager $sandboxes): void
    {
        if (blank($thread->local_repo_path)) {
            return;
        }

        $workspace = (string) config('yak.sandbox.workspace_path', '/workspace');
        $sandboxes->exec($thread, ['sh', '-c', 'mkdir -p "$(dirname "$1")" && ln -sfn "$2" "$1"', 'sh', (string) $thread->local_repo_path, $workspace]);
    }

    private function writeNote(Teleport $teleport, Thread $thread, EnvironmentNote $note): void
    {
        $sections = [];
        $workspace = (string) config('yak.sandbox.workspace_path', '/workspace');

        if (filled($thread->local_repo_path)) {
            $sections['Paths'] = "The project lives at {$workspace}. {$thread->local_repo_path} is a symlink to it, so paths from the person's computer resolve here.";
        }

        $missing = $teleport->payload['missing_directories'] ?? [];

        if ($missing !== []) {
            $sections['Directories that are not here'] = array_values($missing);
        }

        $note->write($thread, $sections);
    }

    public function failed(Throwable $exception): void
    {
        $teleport = Teleport::query()->find($this->teleportId);

        if ($teleport !== null) {
            app(Teleports::class)->fail($teleport, 'teleport_failed', 'Preparing the sandbox failed.');
        }
    }
}
