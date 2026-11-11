<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Teleport down: waits for or stops the turn, commits and pushes, syncs the session and builds the manifest.
 */
class PrepareTeleportDownJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $teleportId) {}

    public function handle(): void {}
}
