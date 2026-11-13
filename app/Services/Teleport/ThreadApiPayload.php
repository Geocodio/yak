<?php

namespace App\Services\Teleport;

use App\Enums\TeleportDirection;
use App\Enums\TeleportState;
use App\Enums\TurnState;
use App\Models\Device;
use App\Models\TaskRun;
use App\Models\Teleport;
use App\Models\Thread;

/**
 * The thread body of the CLI API (contract section 4).
 */
class ThreadApiPayload
{
    /**
     * @return array<string, mixed>
     */
    public function build(Thread $thread, Device $device): array
    {
        $ownerDevice = $thread->ownerDevice;
        $teleport = $thread->openTeleport();

        return [
            'id' => $thread->id,
            'public_id' => $thread->public_id,
            'title' => $thread->title,
            'repo' => $thread->repo,
            'branch' => $thread->branch,
            'status' => $thread->status->value,
            'visibility' => $thread->visibility->value,
            'created_by_user_id' => $thread->created_by_user_id,
            'continued_from_thread_id' => $thread->continued_from_thread_id,
            'owner_side' => $thread->owner_side->value,
            'owner_device' => $ownerDevice === null ? null : [
                'id' => $ownerDevice->id,
                'name' => $ownerDevice->name,
                'is_this_device' => $ownerDevice->id === $device->id,
            ],
            'waiting_for_computer' => $thread->waiting_for_computer_at !== null,
            'turn_running' => TaskRun::query()->where('thread_id', $thread->id)->where('turn_state', TurnState::Running)->exists(),
            'teleport' => $teleport === null ? null : [
                'teleport_id' => $teleport->id,
                'direction' => $teleport->direction->value,
                'device_id' => $teleport->device_id,
                'state' => $teleport->state->value,
            ],
            'claude_account' => ['name' => $thread->claudeAccount?->name],
            'active_session_id' => $thread->activeSession()?->session_id,
            'last_teleport_up' => $this->lastTeleportUp($thread),
            'held_event_count' => $thread->heldEvents()->whereNull('delivered_at')->count(),
            'repos' => $thread->repositories->map(fn ($repository): array => [
                'repo' => $repository->repo,
                'role' => $repository->role->value,
                'branch' => $repository->branch,
                'sandbox_path' => $repository->sandbox_path,
            ])->values()->all(),
            'url' => route('threads.show', $thread),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function lastTeleportUp(Thread $thread): ?array
    {
        /** @var Teleport|null $up */
        $up = $thread->teleports()
            ->where('direction', TeleportDirection::Up)
            ->where('state', TeleportState::Completed)
            ->latest('completed_at')
            ->first();

        if ($up === null) {
            return null;
        }

        return [
            'teleport_id' => $up->id,
            'at' => $up->completed_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'branch' => $thread->branch,
            'head_sha' => $thread->branch === null ? null : ($up->branch_heads[$thread->branch] ?? null),
            'device_id' => $up->device_id,
        ];
    }
}
