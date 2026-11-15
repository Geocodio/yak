<?php

namespace App\Http\Resources;

use App\Models\Thread;
use App\Services\Threads\Preview\ThreadPreviewDevServer;
use App\Services\Threads\Preview\ThreadPreviewPanelState;
use App\Services\Threads\Preview\ThreadPreviewStatus;

/**
 * The body of the app's preview status: the web's status plus what the pane needs
 * to explain a stalled preview, so one poll gives it everything that changes.
 *
 * `state` is an open enum. Today it is one of `ready`, `failing`, `starting_dev_server`,
 * `waking_sandbox`, `waiting_for_slot`, `no_manifest`, `invalid_manifest`, `destroyed`,
 * `closed` and `local_machine`; a client treats any other value as unknown.
 * It reads recorded state only and never wakes or starts anything.
 */
final class AppPreviewStatusData
{
    /**
     * @return array{state: string, steps: list<string>, log: list<string>, error: ?string, url: ?string, port: ?int, coldStartCommand: ?string, setup: array{intent: ?string, isRunning: bool, endedWithoutManifest: bool}}
     */
    public static function for(Thread $thread): array
    {
        $status = app(ThreadPreviewStatus::class)->for($thread);
        $hint = app(ThreadPreviewDevServer::class)->manifestHint($thread);

        return [
            ...$status,
            'port' => $hint['port'],
            'coldStartCommand' => $hint['coldStart'],
            'setup' => app(ThreadPreviewPanelState::class)->setupFor($thread, $status['state']),
        ];
    }
}
