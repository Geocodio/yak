<?php

namespace App\Jobs;

use App\Exceptions\SandboxCommandFailed;
use App\Exceptions\TeleportError;
use App\Models\Teleport;
use App\Services\Teleport\TeleportDownPreparer;
use App\Services\Teleport\Teleports;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Teleport down: waits for or stops the turn, commits and pushes, syncs the session and builds the manifest.
 * It releases itself while a turn runs and ends when the teleport reaches a terminal state.
 */
class PrepareTeleportDownJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 0;

    public function __construct(public string $teleportId) {}

    public function handle(TeleportDownPreparer $preparer, Teleports $teleports): void
    {
        $teleport = Teleport::query()->find($this->teleportId);

        if ($teleport === null || $teleport->state->isTerminal()) {
            return;
        }

        try {
            if (! $preparer->prepare($teleport)) {
                $this->release(5);
            }
        } catch (Throwable $exception) {
            $isKnown = $exception instanceof TeleportError || $exception instanceof SandboxCommandFailed;
            $teleports->fail($teleport->refresh(), 'teleport_failed', $isKnown ? $exception->getMessage() : 'Preparing the thread for your computer failed.');
            report($exception);
        }
    }
}
