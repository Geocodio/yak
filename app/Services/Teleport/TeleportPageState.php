<?php

namespace App\Services\Teleport;

use App\Enums\TeleportDirection;
use App\Enums\TeleportState;
use App\Enums\ThreadOwnerSide;
use App\Enums\TurnState;
use App\Models\Device;
use App\Models\HeldThreadEvent;
use App\Models\TaskRun;
use App\Models\Teleport;
use App\Models\Thread;
use App\Models\User;
use App\Services\Threads\ThreadAccess;

/**
 * The `teleport` block of the thread page. The shape is declared on ThreadPageData.
 */
class TeleportPageState
{
    public function __construct(private readonly ThreadAccess $access, private readonly HeldEvents $heldEvents) {}

    /**
     * @return array{
     *     state: 'none'|'waiting'|'teleporting'|'on_device',
     *     ownerDevice: ?array{id: int, name: string},
     *     ownerName: ?string,
     *     since: ?string,
     *     viewerIsOwner: bool,
     *     command: string,
     *     link: string,
     *     hasDevices: bool,
     *     runningTurn: bool,
     *     requestedRunningTurn: ?string,
     *     openTeleportState: ?string,
     *     failure: ?array{code: string, message: string},
     *     heldEvents: list<array{id: int, kind: string, title: string, summary: string, pullRequestUrl: ?string, createdAt: string, isTinted: bool}>,
     *     environmentNote: ?string,
     *     environmentSwitch: ?array{from: string, to: string, at: string},
     *     accountName: ?string,
     *     continuedFromThreadId: ?string,
     *     continuedFromTitle: ?string,
     *     canTeleport: bool,
     * }
     */
    public function for(Thread $thread, ?User $viewer): array
    {
        $open = $thread->openTeleport();
        $onDevice = $thread->owner_side === ThreadOwnerSide::Device;
        $device = $onDevice ? $thread->ownerDevice : null;
        $viewerIsOwner = $device !== null && $viewer !== null && $device->user_id === $viewer->id;

        $state = match (true) {
            $onDevice => 'on_device',
            $open?->direction === TeleportDirection::Down => 'teleporting',
            $open === null && $thread->waiting_for_computer_at !== null => 'waiting',
            default => 'none',
        };

        $since = match ($state) {
            'on_device' => $thread->owner_changed_at,
            'teleporting' => $open?->created_at,
            'waiting' => $thread->waiting_for_computer_at,
            default => null,
        };

        $canDrive = $this->access->canDrive($viewer, $thread);

        return [
            'state' => $state,
            'ownerDevice' => $viewerIsOwner ? ['id' => $device->id, 'name' => $device->name] : null,
            'ownerName' => $device?->user?->name,
            'since' => $since?->toIso8601String(),
            'viewerIsOwner' => $viewerIsOwner,
            'command' => $onDevice && ! $viewerIsOwner ? '' : "yak teleport {$thread->public_id}",
            'link' => "yak://teleport/{$thread->public_id}",
            'hasDevices' => $viewer !== null && Device::query()->where('user_id', $viewer->id)->whereNull('revoked_at')->exists(),
            'runningTurn' => TaskRun::query()->where('thread_id', $thread->id)->where('turn_state', TurnState::Running)->exists(),
            'requestedRunningTurn' => $thread->teleport_requested_running_turn,
            'openTeleportState' => $open?->state->value,
            'failure' => $this->failure($thread, $open),
            'heldEvents' => $onDevice ? $this->heldEventList($thread) : [],
            'environmentNote' => $this->environmentNote($thread),
            'environmentSwitch' => $this->environmentSwitch($thread),
            'accountName' => $thread->claudeAccount?->name,
            'continuedFromThreadId' => $thread->continued_from_thread_id === null ? null : Thread::query()->withoutGlobalScopes()->find($thread->continued_from_thread_id, ['id', 'public_id'])?->public_id,
            'continuedFromTitle' => $thread->continued_from_thread_id === null ? null : Thread::query()->whereKey($thread->continued_from_thread_id)->value('title'),
            'canTeleport' => $onDevice ? $viewerIsOwner : $canDrive,
        ];
    }

    /**
     * @return array{code: string, message: string}|null
     */
    private function failure(Thread $thread, ?Teleport $open): ?array
    {
        if ($open !== null) {
            return null;
        }

        $last = $thread->teleports()->latest('id')->first();

        if ($last === null || ! in_array($last->state, [TeleportState::Failed, TeleportState::Expired], true)) {
            return null;
        }

        return ['code' => (string) $last->failure_code, 'message' => (string) $last->failure_message];
    }

    /**
     * @return list<array{id: int, kind: string, title: string, summary: string, pullRequestUrl: ?string, createdAt: string, isTinted: bool}>
     */
    private function heldEventList(Thread $thread): array
    {
        return array_values($thread->heldEvents()->whereNull('delivered_at')->oldest()->get()->map(fn (HeldThreadEvent $event): array => [
            'id' => $event->id,
            'kind' => $event->kind->value,
            'title' => $event->title,
            'summary' => $event->summary,
            'pullRequestUrl' => $thread->isPrivate() ? null : $event->pull_request_url,
            'createdAt' => (string) $event->created_at?->toIso8601String(),
            'isTinted' => $this->heldEvents->tinted($event),
        ])->all());
    }

    /**
     * The last completed side switch, which the conversation marks with a divider.
     *
     * @return array{from: string, to: string, at: string}|null
     */
    private function environmentSwitch(Thread $thread): ?array
    {
        $last = $thread->teleports()->where('state', TeleportState::Completed)->whereNotNull('completed_at')->latest('completed_at')->latest('id')->first();

        if ($last === null) {
            return null;
        }

        $isUp = $last->direction === TeleportDirection::Up;

        return ['from' => $isUp ? 'your computer' : 'Yak', 'to' => $isUp ? 'Yak' : 'your computer', 'at' => (string) $last->completed_at?->toIso8601String()];
    }

    /**
     * What changed between the two machines at the last teleport up, shown collapsed.
     */
    private function environmentNote(Thread $thread): ?string
    {
        $up = $thread->teleports()->where('direction', TeleportDirection::Up)->where('state', TeleportState::Completed)->latest('id')->first();

        if ($up === null) {
            return null;
        }

        $lines = [];

        $device = Device::query()->find($up->device_id);

        if ($device !== null && filled($device->os)) {
            $lines[] = "Moved from {$device->os} {$device->arch} to a Linux sandbox.";
        }

        foreach ($up->payload['refresh_ran'] ?? [] as $command) {
            $lines[] = "Ran {$command}";
        }

        return $lines === [] ? null : implode("\n", $lines);
    }
}
