<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\SessionStore\SessionStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

/**
 * Internal session store protocol for the broker and the Go CLI. The thread is
 * resolved here, without the visibility scope, because callers have no user.
 */
class SessionStoreController extends Controller
{
    public function __construct(private readonly SessionStore $store) {}

    public function append(Request $request, int $thread): JsonResponse
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
                $this->thread($thread),
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

    public function length(Request $request, int $thread): JsonResponse
    {
        $data = $this->validatedPath($request);

        return response()->json(['length' => $this->store->length($this->thread($thread), $data['session_id'], $data['rel_path'])]);
    }

    public function checkpoint(Request $request, int $thread): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string'],
            'rel_path' => ['required', 'string', 'max:500'],
            'sha256' => ['required', 'string', 'size:64'],
        ]);
        abort_unless($this->store->isValidPath($data['session_id'], $data['rel_path']), 422, 'Invalid session file path.');

        $thread = $this->thread($thread);
        $matched = $this->store->checkpoint($thread, $data['session_id'], $data['rel_path'], $data['sha256']);

        return response()->json(
            ['matched' => $matched, 'length' => $this->store->length($thread, $data['session_id'], $data['rel_path'])],
            $matched ? 200 : 409,
        );
    }

    public function file(Request $request, int $thread): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'string'],
            'rel_path' => ['required', 'string', 'max:500'],
            'content' => ['present', 'string'],
        ]);

        $content = base64_decode($data['content'], true);
        abort_if($content === false, 422, 'content is not valid base64.');
        abort_unless($this->store->isValidPath($data['session_id'], $data['rel_path']), 422, 'Invalid session file path.');

        $thread = $this->thread($thread);

        try {
            $stored = match ($this->store->modeFor($data['rel_path'])) {
                'whole' => $this->putWhole($thread, $data['session_id'], $data['rel_path'], $content),
                'immutable' => $this->store->putImmutable($thread, $data['session_id'], $data['rel_path'], $content),
                default => throw new InvalidArgumentException('Unsupported file path.'),
            };
        } catch (InvalidArgumentException $exception) {
            abort(422, $exception->getMessage());
        }

        return response()->json(['stored' => $stored]);
    }

    public function files(Request $request, int $thread): JsonResponse
    {
        $data = $request->validate(['session_id' => ['required', 'string']]);

        return response()->json(['files' => $this->store->files($this->thread($thread), $data['session_id'])]);
    }

    public function read(Request $request, int $thread): Response
    {
        $data = $this->validatedPath($request) + $request->validate(['offset' => ['nullable', 'integer', 'min:0']]);
        $thread = $this->thread($thread);

        $exists = array_any(
            $this->store->files($thread, $data['session_id']),
            fn (array $file): bool => $file['rel_path'] === $data['rel_path'],
        );
        abort_unless($exists, 404);

        $bytes = $this->store->read($thread, $data['session_id'], $data['rel_path']);
        $body = substr($bytes, (int) ($data['offset'] ?? 0));

        return response($body, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) strlen($body),
        ]);
    }

    public function tree(Request $request, int $thread): JsonResponse
    {
        $data = $request->validate(['session_id' => ['required', 'string']]);

        return response()->json(['entries' => $this->store->tree($this->thread($thread), $data['session_id'])]);
    }

    /**
     * @return array{session_id: string, rel_path: string}
     */
    private function validatedPath(Request $request): array
    {
        $data = $request->validate([
            'session_id' => ['required', 'string'],
            'rel_path' => ['required', 'string', 'max:500'],
        ]);
        abort_unless($this->store->isValidPath($data['session_id'], $data['rel_path']), 422, 'Invalid session file path.');

        return $data;
    }

    private function putWhole(Thread $thread, string $sessionId, string $relativePath, string $content): bool
    {
        $this->store->putWhole($thread, $sessionId, $relativePath, $content);

        return true;
    }

    private function thread(int $id): Thread
    {
        return Thread::withoutGlobalScopes()->findOrFail($id);
    }
}
