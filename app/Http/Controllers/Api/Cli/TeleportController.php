<?php

namespace App\Http\Controllers\Api\Cli;

use App\Enums\TeleportDirection;
use App\Enums\TeleportState;
use App\Http\Controllers\Api\Cli\Concerns\ResolvesCliThread;
use App\Http\Controllers\Controller;
use App\Http\Requests\Cli\BeginTeleportRequest;
use App\Models\Thread;
use App\Services\Teleport\TeleportDownCompleter;
use App\Services\Teleport\Teleports;
use App\Services\Teleport\TeleportUpCompleter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeleportController extends Controller
{
    use ResolvesCliThread;

    public function __construct(private readonly Teleports $teleports) {}

    public function begin(BeginTeleportRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if ($validated['direction'] === 'down') {
            $down = $this->teleports->beginDown($this->cliDevice($request), $validated);

            return response()->json([
                'teleport_id' => $down['teleport']->id,
                'resumed' => $down['resumed'],
                'teleport' => $down['teleport']->toApi(),
            ]);
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
        $found = $this->teleports->get($this->cliDevice($request), $teleport);
        $body = $found->toApi();

        if ($found->state === TeleportState::Ready && $found->manifest !== null) {
            return response()->json(['teleport' => $body, ...$found->manifest]);
        }

        return response()->json($body);
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

    public function complete(Request $request, string $teleport, TeleportUpCompleter $upCompleter, TeleportDownCompleter $downCompleter): JsonResponse
    {
        $device = $this->cliDevice($request);
        $teleportModel = $this->teleports->get($device, $teleport);

        if ($teleportModel->direction === TeleportDirection::Down) {
            $data = $request->validate([
                'applied' => ['required', 'array', 'min:1', 'max:10'],
                'applied.*.repo' => ['required', 'string', 'max:200'],
                'applied.*.status' => ['required', 'in:applied,skipped,failed'],
                'applied.*.mode' => ['nullable', 'in:fast_forward,stash,worktree,clone'],
                'applied.*.head_sha' => ['nullable', 'string', 'max:64'],
                'applied.*.branch' => ['nullable', 'string', 'max:255'],
                'applied.*.path' => ['nullable', 'string', 'max:500'],
                'applied.*.reason' => ['nullable', 'string', 'max:500'],
                'downloaded' => ['present', 'array', 'max:2000'],
                'downloaded.*.session_id' => ['required', 'string', 'max:64'],
                'downloaded.*.rel_path' => ['required', 'string', 'max:500'],
                'downloaded.*.sha256' => ['required', 'string', 'size:64'],
                'local_session_backup' => ['nullable', 'string', 'max:255'],
            ]);
            $result = $downCompleter->complete($device, $teleportModel, $data);

            return response()->json($result['body'], $result['status']);
        }

        $data = $request->validate([
            'repos' => ['required', 'array', 'min:1', 'max:10'],
            'repos.*.repo' => ['required', 'string', 'max:200'],
            'repos.*.branch' => ['nullable', 'string', 'max:255'],
            'repos.*.head_sha' => ['nullable', 'string', 'max:64'],
            'message' => ['nullable', 'string', 'max:20000'],
        ]);

        $result = $upCompleter->complete($device, $teleportModel, $data);

        return response()->json($result['body'], $result['status']);
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
