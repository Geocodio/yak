<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Renders one v3 walkthrough for one task (spec §7-8).
 *
 * Keyed on the task rather than on an artifact, because a v3 run produces
 * one cut from many clips: the job loads `script`, `manifest`, `shot` and
 * `voiceover` artifacts by role. The legacy single-webm pipeline keeps
 * using RenderVideoJob until no v2 artifacts remain.
 */
class RenderWalkthroughJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(public int $taskId)
    {
        $this->onQueue('yak-render');
    }

    /**
     * Filled in by a later task; the persister already dispatches this so
     * the wiring can be tested before the renderer exists.
     */
    public function handle(): void
    {
        // Intentionally a no-op stub.
    }
}
