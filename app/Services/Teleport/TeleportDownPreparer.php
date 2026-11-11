<?php

namespace App\Services\Teleport;

use App\Enums\RepositoryRole;
use App\Enums\TeleportState;
use App\Enums\TeleportStep;
use App\Enums\TurnState;
use App\Exceptions\SandboxCommandFailed;
use App\Exceptions\TeleportError;
use App\Models\SessionFile;
use App\Models\Teleport;
use App\Models\Thread;
use App\Services\SessionStore\SessionSync;
use App\Services\Threads\Contracts\SandboxManager;
use App\Services\Threads\Contracts\SessionFileSync;
use App\Services\Threads\ThreadSandbox;
use App\Services\Threads\ThreadTurns;
use App\Services\Threads\WipPreserver;

/**
 * Yak's side of a teleport down: let the turn finish or stop it, commit and push, sync the session, build the manifest.
 * Owner fields are never written here; a failure leaves them untouched.
 */
class TeleportDownPreparer
{
    public function __construct(
        private readonly ThreadTurns $turns,
        private readonly ThreadSandbox $sandbox,
        private readonly SandboxManager $sandboxes,
        private readonly WipPreserver $wip,
        private readonly RepositoryWip $repositoryWip,
        private readonly SessionFileSync $sessions,
        private readonly SessionSync $syncVerifier,
        private readonly BranchHeadVerifier $heads,
        private readonly DownManifestBuilder $manifests,
    ) {}

    /**
     * True when the teleport is ready or already ended; false when the job should run again shortly.
     */
    public function prepare(Teleport $teleport): bool
    {
        if ($teleport->state->isTerminal() || $teleport->state === TeleportState::Ready) {
            return true;
        }

        $thread = Thread::query()->withoutGlobalScopes()->findOrFail($teleport->thread_id);

        if (! $this->settleTurn($teleport, $thread)) {
            return false;
        }

        if (! $this->wakeSandbox($thread)) {
            return false;
        }

        $heads = $this->commitAndPush($teleport, $thread);
        $this->syncSession($teleport, $thread);
        $this->verifyHeads($thread, $heads);

        $teleport->forceFill([
            'branch_heads' => $heads,
            'memory_snapshot' => $this->manifests->memorySnapshot($thread),
        ])->save();
        $teleport->forceFill(['manifest' => $this->manifests->build($teleport->refresh()), 'state' => TeleportState::Ready])->save();

        return true;
    }

    private function settleTurn(Teleport $teleport, Thread $thread): bool
    {
        $active = ThreadTurns::activeTurn($thread);

        if ($active === null) {
            return true;
        }

        $isRunning = $active->getAttribute('turn_state') === TurnState::Running;

        if ($isRunning && $teleport->running_turn !== 'stop') {
            $teleport->forceFill(['state' => TeleportState::WaitingForTurn])->save();
            $teleport->progressStep(TeleportStep::WaitTurn, 'running');

            return false;
        }

        $teleport->progressStep(TeleportStep::StopTurn, 'running');
        $this->turns->stop($thread);

        if (ThreadTurns::activeTurn($thread) !== null) {
            return false;
        }

        $teleport->progressStep(TeleportStep::StopTurn, 'done');

        return true;
    }

    private function wakeSandbox(Thread $thread): bool
    {
        $wake = $this->sandbox->ensureAwake($thread);

        if ($wake->ready) {
            return true;
        }

        if ($wake->waitingForSlot || $wake->restoring) {
            return false;
        }

        throw new TeleportError('teleport_failed', 'Yak could not wake the sandbox.');
    }

    /**
     * @return array<string, string> Repo name to the head pushed for it.
     */
    private function commitAndPush(Teleport $teleport, Thread $thread): array
    {
        $this->markDone($teleport, TeleportStep::WaitTurn);
        $teleport->forceFill(['state' => TeleportState::Preparing])->save();
        $teleport->progressStep(TeleportStep::CommitWip, 'running');
        $made = [$thread->repo => $this->wip->preserveForTeleport($thread)->committed];
        $failures = [];
        $heads = [];
        $teleport->progressStep(TeleportStep::CommitWip, 'done');
        $teleport->progressStep(TeleportStep::Push, 'running');

        foreach ($thread->repositories()->get() as $repository) {
            if ($repository->role === RepositoryRole::Context || blank($repository->branch)) {
                continue;
            }

            if ($repository->role === RepositoryRole::Primary) {
                $head = trim($this->sandboxes->exec($thread, ['git', '-C', $repository->sandbox_path, 'rev-parse', 'HEAD'])->output());

                if ($head === '') {
                    throw new TeleportError('teleport_failed', "Reading the head of {$repository->repo} failed.");
                }

                $heads[$repository->repo] = $head;

                continue;
            }

            try {
                $made[$repository->repo] = $this->repositoryWip->preserve($thread, $repository)->committed;
            } catch (SandboxCommandFailed $exception) {
                $made[$repository->repo] = false;
                $failures[$repository->repo] = ['code' => str_starts_with($exception->getMessage(), 'WIP push') ? 'push_failed' : 'wip_failed', 'message' => $exception->getMessage()];

                continue;
            }

            $head = trim($this->sandboxes->exec($thread, ['git', '-C', $repository->sandbox_path, 'rev-parse', 'HEAD'])->output());

            if ($head === '') {
                $failures[$repository->repo] = ['code' => 'head_unreadable', 'message' => "Reading the head of {$repository->repo} failed."];

                continue;
            }

            $heads[$repository->repo] = $head;
        }

        $teleport->forceFill(['payload' => [...($teleport->payload ?? []), 'wip_commit_made' => $made, 'repo_failures' => $failures]])->save();
        $teleport->progressStep(TeleportStep::Push, 'done');

        return $heads;
    }

    private function syncSession(Teleport $teleport, Thread $thread): void
    {
        $teleport->progressStep(TeleportStep::SyncSession, 'running');
        $this->sessions->syncFromSandbox($thread);

        $files = SessionFile::query()->where(['thread_id' => $thread->id, 'mode' => 'append'])->get();

        foreach ($files as $file) {
            if (! $this->syncVerifier->checkpointMatches($thread, $file->session_id, $file->rel_path) || $file->refresh()->sha256 === null) {
                throw new TeleportError('session_incomplete', 'The session upload to Yak did not complete; teleport was not started.');
            }
        }

        $teleport->progressStep(TeleportStep::SyncSession, 'done');
    }

    /**
     * @param  array<string, string>  $heads
     */
    private function verifyHeads(Thread $thread, array $heads): void
    {
        foreach ($thread->repositories()->get() as $repository) {
            $head = $heads[$repository->repo] ?? null;

            if ($head !== null && $this->heads->headOf($repository->repo, (string) $repository->branch) !== $head) {
                throw new TeleportError('teleport_failed', "{$repository->branch} on GitHub is not at the pushed head yet.");
            }
        }
    }

    private function markDone(Teleport $teleport, TeleportStep $step): void
    {
        if (collect($teleport->progress)->contains('step', $step->value)) {
            $teleport->progressStep($step, 'done');
        }
    }
}
