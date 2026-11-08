<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\SessionStore\SessionStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class SessionStoreController extends Controller
{
    public function __construct(private readonly SessionStore $store) {}

    public function append(Request $request, Thread $thread): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string'],
            'rel_path' => ['required', 'string', 'max:500'],
            'offset' => ['required', 'integer', 'min:0'],
            'chunk' => ['present', 'string'],
            'chunk_sha256' => ['required', 'string', 'size:64'],
            'tail_sha256' => ['required', 'string', 'size:64'],
        ]);

        $chunk = base64_decode($data['chunk'], true);
        abort_if($chunk === false, 422, 'chunk is not valid base64.');

        try {
            $outcome = $this->store->append(
                $thread->id,
                $data['session_id'],
                $data['rel_path'],
                (int) $data['offset'],
                $chunk,
                $data['chunk_sha256'],
                $data['tail_sha256'],
            );
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        if ($outcome->status === 409) {
            return response()->json(['reason' => $outcome->reason, 'length' => $outcome->length], 409);
        }

        return response()->json(['length' => $outcome->length]);
    }

    public function length(Request $request, Thread $thread): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string'],
            'rel_path' => ['required', 'string', 'max:500'],
        ]);

        return response()->json(['length' => $this->store->length($thread->id, $data['session_id'], $data['rel_path'])]);
    }

    public function checkpoint(Request $request, Thread $thread): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string'],
            'rel_path' => ['required', 'string', 'max:500'],
            'sha256' => ['required', 'string', 'size:64'],
        ]);

        $matched = $this->store->checkpoint($thread->id, $data['session_id'], $data['rel_path'], $data['sha256']);

        return response()->json(
            ['matched' => $matched, 'length' => $this->store->length($thread->id, $data['session_id'], $data['rel_path'])],
            $matched ? 200 : 409,
        );
    }

    public function file(Request $request, Thread $thread): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string'],
            'rel_path' => ['required', 'string', 'max:500'],
            'content' => ['present', 'string'],
        ]);

        $content = base64_decode($data['content'], true);
        abort_if($content === false, 422, 'content is not valid base64.');

        try {
            $created = match ($this->store->modeFor($data['rel_path'])) {
                'whole' => $this->putWhole($thread, $data['session_id'], $data['rel_path'], $content),
                'immutable' => $this->store->putImmutable($thread->id, $data['session_id'], $data['rel_path'], $content),
                default => throw new InvalidArgumentException('Unsupported file path.'),
            };
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->json(['stored' => $created]);
    }

    private function putWhole(Thread $thread, string $sessionId, string $relPath, string $content): bool
    {
        $this->store->putWhole($thread->id, $sessionId, $relPath, $content);

        return true;
    }
}
