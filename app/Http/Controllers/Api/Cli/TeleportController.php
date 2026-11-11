<?php

namespace App\Http\Controllers\Api\Cli;

use App\Exceptions\TeleportError;
use App\Http\Controllers\Api\Cli\Concerns\ResolvesCliThread;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cli\BeginTeleportRequest;
use App\Models\Thread;
use App\Services\Teleport\Teleports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeleportController extends Controller
{
    use ResolvesCliThread;

    public function __construct(private readonly Teleports $teleports) {}

    public function begin(BeginTeleportRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if ($validated['direction'] !== 'up') {
            throw new TeleportError('direction_unavailable', 'Teleporting down is not available yet.', 422);
        }

        $result = $this->teleports->beginUp($this->cliDevice($request), $validated);
        $thread = $result['thread'];

        return response()->json([
            'teleport_id' => $result['teleport']->id,
            'resumed' => $result['resumed'],
            'thread' => $this->threadBody($thread, $result['isNew']),
            'repos' => $result['repos'],
            'expires_at' => $result['teleport']->expires_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ]);
    }

    public function show(Request $request, string $teleport): JsonResponse
    {
        return response()->json($this->teleports->get($this->cliDevice($request), $teleport)->toApi());
    }

    public function cancel(Request $request, string $teleport): JsonResponse
    {
        $device = $this->cliDevice($request);
        $data = $request->validate([
            'reason' => ['required', 'in:user_cancelled,clash,dirty_tree,diverged,error'],
            'detail' => ['nullable', 'string', 'max:500'],
        ]);

        $cancelled = $this->teleports->cancel($device, $this->teleports->get($device, $teleport), $data['reason'], $data['detail'] ?? null);

        return response()->json($cancelled->toApi());
    }

    /**
     * @return array<string, mixed>
     */
    private function threadBody(Thread $thread, bool $isNew): array
    {
        return [
            'id' => $thread->id,
            'is_new' => $isNew,
            'continued_from_thread_id' => $thread->continued_from_thread_id,
            'slug' => $thread->name,
            'visibility' => $thread->visibility->value,
            'first_teleported_at' => $thread->first_teleported_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'claude_account' => ['name' => $thread->claudeAccount?->name],
            'url' => route('threads.show', $thread),
        ];
    }
}
