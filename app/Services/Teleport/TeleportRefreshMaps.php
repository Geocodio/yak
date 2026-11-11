<?php

namespace App\Services\Teleport;

use App\Models\Thread;
use App\Models\ThreadRepository;
use App\Services\Threads\RefreshTriggers;

/**
 * The `refresh:` map of one repository checkout, shaped for the teleport manifest.
 */
class TeleportRefreshMaps
{
    public function __construct(private readonly RefreshTriggers $refresh) {}

    /**
     * @return list<array{glob: string, command: string}>
     */
    public function forRepository(Thread $thread, ThreadRepository $repository): array
    {
        return collect($this->refresh->configFor($thread, $repository->sandbox_path))
            ->map(fn (string $command, string $glob): array => ['glob' => $glob, 'command' => $command])
            ->values()
            ->all();
    }
}
