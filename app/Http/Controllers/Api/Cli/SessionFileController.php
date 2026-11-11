<?php

namespace App\Http\Controllers\Api\Cli;

use App\Http\Controllers\Api\Cli\Concerns\ResolvesCliThread;
use App\Http\Controllers\Controller;
use App\Http\Responses\CliError;
use App\Models\Thread;
use App\Services\SessionStore\SessionStore;
use App\Services\Teleport\TeleportAuthorizer;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Device-facing wrapper over the session store. Every call needs an open teleport of the calling device.
 */
class SessionFileController extends Controller
{
    use ResolvesCliThread;

    private const int CHUNK_LIMIT_BYTES = 1024 * 1024;

    private const int FILE_LIMIT_BYTES = 8 * 1024 * 1024;

    public function __construct(private readonly SessionStore $store, private readonly TeleportAuthorizer $authorizer) {}

    public function length(Request $request, int $thread): JsonResponse
    {
        $thread = $this->authorized($request, $thread, false);
        $data = $this->validatedPath($request);

        return response()->json(['length' => $this->store->length($thread, $data['session_id'], $data['rel_path'])]);
    }

    public function append(Request $request, int $thread): JsonResponse
    {
        $thread = $this->authorized($request, $thread, true);
        $data = $request->validate([
            'session_id' => ['required', 'string'],
            'rel_path' => ['required', 'string', 'max:500'],
            'offset' => ['required', 'integer', 'min:0'],
            'chunk' => ['present', 'string'],
            'chunk_sha256' => ['required', 'string', 'size:64'],
            'tail_sha256' => ['required', 'string', 'size:64'],
        ]);
        $chunk = $this->decode($data['chunk'], 'chunk', self::CHUNK_LIMIT_BYTES);

        try {
            $outcome = $this->store->append(
                $thread,
                $data['session_id'],
                $data['rel_path'],
                (int) $data['offset'],
                $chunk,
                $data['chunk_sha256'],
                $data['tail_sha256'],
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['rel_path' => [$exception->getMessage()]]);
        }

        if ($outcome->status === 409) {
            return $this->conflict(['reason' => $outcome->reason, 'length' => $outcome->length]);
        }

        return response()->json(['length' => $outcome->length]);
    }

    public function checkpoint(Request $request, int $thread): JsonResponse
    {
        $thread = $this->authorized($request, $thread, true);
        $data = $request->validate([
            'session_id' => ['required', 'string'],
            'rel_path' => ['required', 'string', 'max:500'],
            'sha256' => ['required', 'string', 'size:64'],
        ]);
        $this->assertPath($data['session_id'], $data['rel_path']);

        $matched = $this->store->checkpoint($thread, $data['session_id'], $data['rel_path'], $data['sha256']);
        $length = $this->store->length($thread, $data['session_id'], $data['rel_path']);

        return $matched
            ? response()->json(['matched' => true, 'length' => $length])
            : $this->conflict(['matched' => false, 'length' => $length]);
    }

    public function file(Request $request, int $thread): JsonResponse
    {
        $thread = $this->authorized($request, $thread, true);
        $data = $request->validate([
            'session_id' => ['required', 'string'],
            'rel_path' => ['required', 'string', 'max:500'],
            'content' => ['present', 'string'],
        ]);
        $content = $this->decode($data['content'], 'content', self::FILE_LIMIT_BYTES);
        $this->assertPath($data['session_id'], $data['rel_path']);

        try {
            $stored = match ($this->store->modeFor($data['rel_path'])) {
                'whole' => $this->putWhole($thread, $data['session_id'], $data['rel_path'], $content),
                'immutable' => $this->store->putImmutable($thread, $data['session_id'], $data['rel_path'], $content),
                default => throw new InvalidArgumentException('Unsupported file path.'),
            };
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['rel_path' => [$exception->getMessage()]]);
        }

        return response()->json(['stored' => $stored]);
    }

    public function files(Request $request, int $thread): JsonResponse
    {
        $thread = $this->authorized($request, $thread, false);
        $data = $request->validate(['session_id' => ['required', 'string']]);

        return response()->json(['files' => $this->store->files($thread, $data['session_id'])]);
    }

    public function read(Request $request, int $thread): Response|JsonResponse
    {
        $thread = $this->authorized($request, $thread, false);
        $data = $this->validatedPath($request) + $request->validate(['offset' => ['nullable', 'integer', 'min:0']]);

        $exists = array_any(
            $this->store->files($thread, $data['session_id']),
            fn (array $file): bool => $file['rel_path'] === $data['rel_path'],
        );

        if (! $exists) {
            return CliError::make('file_not_found', 'That session file does not exist.', 404);
        }

        $body = substr($this->store->read($thread, $data['session_id'], $data['rel_path']), (int) ($data['offset'] ?? 0));

        return response($body, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Length' => (string) strlen($body),
        ]);
    }

    public function tree(Request $request, int $thread): JsonResponse
    {
        $thread = $this->authorized($request, $thread, false);
        $data = $request->validate(['session_id' => ['required', 'string']]);

        return response()->json(['entries' => $this->store->tree($thread, $data['session_id'])]);
    }

    private function authorized(Request $request, int $threadId, bool $write): Thread
    {
        $thread = $this->cliThread($request, $threadId);
        $this->authorizer->forSession($this->cliDevice($request), $thread, $write)->touchCall();

        return $thread;
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function conflict(array $details): JsonResponse
    {
        return response()->json(
            ['error' => 'session_conflict', 'message' => 'The stored session differs from the one sent.', ...$details, 'details' => (object) $details],
            409,
        );
    }

    private function decode(string $encoded, string $field, int $limit): string
    {
        $decoded = base64_decode($encoded, true);

        if ($decoded === false) {
            throw ValidationException::withMessages([$field => ["{$field} is not valid base64."]]);
        }

        if (strlen($decoded) > $limit) {
            throw new HttpResponseException(CliError::make('payload_too_large', "{$field} is larger than {$limit} bytes.", 413));
        }

        return $decoded;
    }

    private function assertPath(string $sessionId, string $relativePath): void
    {
        if (! $this->store->isValidPath($sessionId, $relativePath)) {
            throw ValidationException::withMessages(['rel_path' => ['Invalid session file path.']]);
        }
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
        $this->assertPath($data['session_id'], $data['rel_path']);

        return $data;
    }

    private function putWhole(Thread $thread, string $sessionId, string $relativePath, string $content): bool
    {
        $this->store->putWhole($thread, $sessionId, $relativePath, $content);

        return true;
    }
}
