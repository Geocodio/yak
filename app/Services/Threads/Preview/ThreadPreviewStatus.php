<?php

namespace App\Services\Threads\Preview;

use App\Enums\ThreadBlockReason;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;

/**
 * Describes where a thread's preview stands without waking or starting
 * anything: it only reads Yak's own state and the cached dev server state.
 */
class ThreadPreviewStatus
{
    public function __construct(
        private ThreadPreviewDevServer $devServer,
        private ThreadPreviewHosts $hosts,
    ) {}

    /**
     * @return array{state: string, steps: list<string>, log: list<string>, error: ?string, url: ?string}
     */
    public function for(Thread $thread): array
    {
        $thread->refresh();

        $state = $this->state($thread);

        return [
            'state' => $state,
            'steps' => match ($state) {
                'waking_sandbox', 'waiting_for_slot' => ['Waking the sandbox'],
                'starting_dev_server' => ['Waking the sandbox', 'Starting the dev server'],
                default => [],
            },
            'log' => $state === 'failing' || $state === 'starting_dev_server' ? $this->devServer->logTail($thread) : [],
            'error' => in_array($state, ['failing', 'invalid_manifest'], true) ? $this->devServer->error($thread) : null,
            'url' => $state === 'ready' ? $this->hosts->urlFor($thread) : null,
        ];
    }

    private function state(Thread $thread): string
    {
        if (! $thread->isOpen()) {
            return 'closed';
        }

        if ($thread->owner_side === ThreadOwnerSide::Device) {
            return 'local_machine';
        }

        return match ($thread->sandbox_state) {
            ThreadSandboxState::None, ThreadSandboxState::Destroying, ThreadSandboxState::Destroyed => 'destroyed',
            ThreadSandboxState::Running => match ($this->devServer->state($thread)) {
                'ready' => 'ready',
                'failing' => 'failing',
                'no_manifest' => 'no_manifest',
                'invalid_manifest' => 'invalid_manifest',
                default => 'starting_dev_server',
            },
            default => $thread->blocked_reason === ThreadBlockReason::WaitingForSandbox ? 'waiting_for_slot' : 'waking_sandbox',
        };
    }
}
