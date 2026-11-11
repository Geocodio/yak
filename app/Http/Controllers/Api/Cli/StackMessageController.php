<?php

namespace App\Http\Controllers\Api\Cli;

use App\Enums\ThreadOwnerSide;
use App\Exceptions\TeleportError;
use App\Http\Controllers\Api\Cli\Concerns\ResolvesCliThread;
use App\Http\Controllers\Controller;
use App\Models\StackMessage;
use App\Models\Thread;
use App\Services\Teleport\StackMessageInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StackMessageController extends Controller
{
    use ResolvesCliThread;

    private const int MAX_LIMIT = 50;

    public function __construct(private readonly StackMessageInbox $inbox) {}

    public function index(Request $request, int $thread): JsonResponse
    {
        $found = $this->ownedThread($request, $thread);
        $limit = min(self::MAX_LIMIT, max(1, (int) $request->query('limit', self::MAX_LIMIT)));
        $messages = $this->inbox->pending($found, $limit + 1);

        $body = [
            'messages' => $messages->take($limit)->map(fn (StackMessage $message): array => $this->present($message))->values()->all(),
            'has_more' => $messages->count() > $limit,
        ];
        $body['owner_side'] = $found->owner_side->value;

        return response()->json($body);
    }

    public function ack(Request $request, int $thread): JsonResponse
    {
        $found = $this->ownedThread($request, $thread);
        $data = $request->validate(['through_id' => ['required', 'integer', 'min:0']]);

        return response()->json(['acked' => $this->inbox->ack($found, (int) $data['through_id'])]);
    }

    private function ownedThread(Request $request, int $threadId): Thread
    {
        $thread = $this->cliThread($request, $threadId);

        if ($thread->owner_side !== ThreadOwnerSide::Device || $thread->owner_device_id !== $this->cliDevice($request)->id) {
            throw new TeleportError('not_owner', 'This thread is not on this computer.');
        }

        return $thread;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StackMessage $message): array
    {
        $from = $message->from_thread_id === null ? null : Thread::query()->withoutGlobalScopes()->find($message->from_thread_id);

        return [
            'id' => $message->id,
            'kind' => $message->kind->value,
            'from_thread' => $from === null ? null : ['id' => $from->id, 'title' => $from->title, 'kind' => $from->kind->value],
            'text' => $message->text,
            'created_at' => $message->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
