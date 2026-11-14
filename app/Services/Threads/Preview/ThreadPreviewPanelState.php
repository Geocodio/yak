<?php

namespace App\Services\Threads\Preview;

use App\Enums\ThreadKind;
use App\Enums\ThreadOwnerSide;
use App\Enums\ThreadSandboxState;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Services\Teleport\TeleportPageState;
use App\Services\Threads\ThreadAccess;
use App\Services\Threads\ThreadTurns;

/**
 * Props for the Preview dock panel. Reads recorded state only and never wakes
 * or starts anything.
 */
class ThreadPreviewPanelState
{
    public function __construct(
        private ThreadPreviewStatus $status,
        private ThreadPreviewDevServer $devServer,
        private ThreadPreviewHosts $hosts,
        private ThreadAccess $access,
    ) {}

    /**
     * @return array{state: 'ready'|'sandbox_asleep'|'waking_sandbox'|'starting_dev_server'|'failing'|'no_manifest'|'invalid_manifest'|'destroyed'|'closed'|'local_machine'|'waiting_for_slot', url: ?string, host: ?string, repoLabel: string, canRestore: bool, safariFallback: bool, targets: list<array{threadId: string, title: string, kind: string, url: ?string, state: string, canRestore: bool}>, awakeCap: int, personAwakeCap: int, teleportCommand: ?string, port: ?int, coldStartCommand: ?string, setup: array{intent: ?string, isRunning: bool, endedWithoutManifest: bool}}
     */
    public function build(Thread $thread, ?User $viewer): array
    {
        $state = $this->stateFor($thread);
        $url = $this->urlFor($thread, $state);

        $hint = $this->devServer->manifestHint($thread);
        $targets = [$this->target($thread, $viewer)];
        $children = $thread->children()
            ->whereIn('kind', [ThreadKind::Implement, ThreadKind::Mockup])
            ->where('sandbox_state', '!=', ThreadSandboxState::None)
            ->orderBy('id')
            ->get();

        foreach ($children as $child) {
            $targets[] = $this->target($child, $viewer);
        }

        return [
            'state' => $state,
            'url' => $url,
            'host' => $url === null ? null : $this->hosts->hostnameFor($thread),
            'repoLabel' => $thread->repo,
            'canRestore' => $this->canRestore($thread, $viewer, $state),
            'safariFallback' => (bool) config('yak.threads.preview_safari_fallback'),
            'targets' => $targets,
            'awakeCap' => (int) config('yak.threads.awake_cap'),
            'personAwakeCap' => (int) config('yak.threads.person_awake_cap'),
            'port' => $hint['port'],
            'coldStartCommand' => $hint['coldStart'],
            'setup' => $this->setupFor($thread, $state),
            'teleportCommand' => $state === 'local_machine' ? (app(TeleportPageState::class)->for($thread, $viewer)['command'] ?: null) : null,
        ];
    }

    /**
     * The latest request the Preview card made of Claude: whether its turn still runs, and whether it
     * ended with the preview file still missing.
     *
     * @return array{intent: ?string, isRunning: bool, endedWithoutManifest: bool}
     */
    private function setupFor(Thread $thread, string $state): array
    {
        $message = ThreadTurnMessage::query()
            ->whereNotNull('intent')
            ->whereHas('taskRun', fn ($runs) => $runs->where('thread_id', $thread->id))
            ->latest('id')
            ->first();

        if ($message === null) {
            return ['intent' => null, 'isRunning' => false, 'endedWithoutManifest' => false];
        }

        $isRunning = ThreadTurns::activeTurn($thread)?->id === $message->task_run_id;

        return [
            'intent' => $message->intent,
            'isRunning' => $isRunning,
            'endedWithoutManifest' => ! $isRunning && $message->intent === 'add_preview_manifest' && $state === 'no_manifest',
        ];
    }

    /**
     * @return 'ready'|'sandbox_asleep'|'waking_sandbox'|'starting_dev_server'|'failing'|'no_manifest'|'invalid_manifest'|'destroyed'|'closed'|'local_machine'|'waiting_for_slot'
     */
    private function stateFor(Thread $thread): string
    {
        /** @var 'ready'|'sandbox_asleep'|'waking_sandbox'|'starting_dev_server'|'failing'|'no_manifest'|'invalid_manifest'|'destroyed'|'closed'|'local_machine'|'waiting_for_slot' $state */
        $state = $this->status->for($thread)['state'];

        $isAsleep = $thread->isOpen()
            && $thread->owner_side === ThreadOwnerSide::Yak
            && in_array($thread->sandbox_state, [ThreadSandboxState::Paused, ThreadSandboxState::Stopped], true);

        return $isAsleep ? 'sandbox_asleep' : $state;
    }

    /** An asleep sandbox still has an address: opening it wakes the sandbox. */
    private function urlFor(Thread $thread, string $state): ?string
    {
        return in_array($state, ['ready', 'sandbox_asleep', 'waking_sandbox', 'starting_dev_server'], true) ? $this->hosts->urlFor($thread) : null;
    }

    private function canRestore(Thread $thread, ?User $viewer, string $state): bool
    {
        return $state === 'destroyed' && $this->access->canDrive($viewer, $thread);
    }

    /**
     * @return array{threadId: string, title: string, kind: string, url: ?string, state: string, canRestore: bool}
     */
    private function target(Thread $thread, ?User $viewer): array
    {
        $state = $this->stateFor($thread);

        return [
            'threadId' => $thread->public_id,
            'title' => $thread->title,
            'kind' => $thread->kind->value,
            'url' => $this->urlFor($thread, $state),
            'state' => $state,
            'canRestore' => $this->canRestore($thread, $viewer, $state),
        ];
    }
}
