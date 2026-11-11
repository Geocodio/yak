<?php

namespace App\Http\Controllers\Api\Cli;

use App\Enums\TurnState;
use App\Http\Controllers\Api\Cli\Concerns\ResolvesCliThread;
use App\Http\Controllers\Controller;
use App\Models\Device;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ThreadLookupController extends Controller
{
    use ResolvesCliThread;

    public function show(Request $request, int $thread): JsonResponse
    {
        $model = $this->cliThread($request, $thread);

        return response()->json(['thread' => $this->payload($model, $this->cliDevice($request))]);
    }

    public function bySession(Request $request, string $sessionId): JsonResponse
    {
        $threadId = ThreadSession::query()->where('session_id', $sessionId)->value('thread_id');
        $model = $this->visibleCliThread($request, $threadId === null ? null : Thread::withoutGlobalScopes()->whereKey($threadId)->first());

        return response()->json(['thread' => $this->payload($model, $this->cliDevice($request))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Thread $thread, Device $device): array
    {
        $ownerDevice = $thread->ownerDevice;
        $teleport = $thread->openTeleport();

        return [
            'id' => $thread->id,
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
            'last_teleport_up' => null,
            'held_event_count' => 0,
            'repos' => $thread->repositories->map(fn ($repository): array => [
                'repo' => $repository->repo,
                'role' => $repository->role->value,
                'branch' => $repository->branch,
                'sandbox_path' => $repository->sandbox_path,
            ])->values()->all(),
            'url' => route('threads.show', $thread),
        ];
    }
}
