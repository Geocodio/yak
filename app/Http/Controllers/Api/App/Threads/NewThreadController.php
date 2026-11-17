<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Enums\TaskMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreAppThreadRequest;
use App\Http\Resources\NewThreadData;
use App\Models\TaskAttachment;
use App\Models\Thread;
use App\Models\User;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;
use App\Services\Threads\SendKeys;
use App\Services\Threads\ThreadAccountUnavailable;
use App\Services\Threads\ThreadStarter;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

class NewThreadController extends Controller
{
    public function create(Request $request): JsonResponse
    {
        return response()->json([
            ...NewThreadData::for($request->user()),
            'models' => app(ClaudeModelCatalog::class)->families(),
        ]);
    }

    public function store(StoreAppThreadRequest $request, ThreadStarter $starter, SendKeys $sendKeys): JsonResponse
    {
        $user = $request->user() ?? abort(401);
        $validated = $request->validated();

        $sendKey = $validated['send_key'];

        // The thread row is the durable record; the cache only replays the answer to a quick repeat.
        if ($existing = $this->threadFor($user, $sendKey)) {
            return response()->json($existing, 201);
        }

        $result = $sendKeys->once("thread-create:{$user->id}:{$sendKey}", function () use ($request, $starter, $user, $validated, $sendKey): array {
            if ($existing = $this->threadFor($user, $sendKey)) {
                return $existing;
            }

            $attachments = TaskAttachment::storeFromRequest($request);

            try {
                $thread = $starter->start(
                    $user,
                    $validated['repo'],
                    TaskMode::from($validated['mode']),
                    $validated['description'],
                    isset($validated['claude_account_id']) ? (int) $validated['claude_account_id'] : null,
                    $validated['model'] ?? null,
                    $attachments,
                    $validated['repositories'] ?? [],
                    $sendKey,
                    $validated['effort'] ?? null,
                );
            } catch (Throwable $exception) {
                TaskAttachment::discard($attachments);

                // Another request with the same key won the unique index between the check and the insert.
                if ($exception instanceof UniqueConstraintViolationException && ($existing = $this->threadFor($user, $sendKey))) {
                    return $existing;
                }

                throw $exception instanceof ThreadAccountUnavailable
                    ? ValidationException::withMessages(['claude_account_id' => $exception->getMessage()])
                    : $exception;
            }

            return ['id' => $thread->public_id];
        });

        return response()->json($result, 201);
    }

    /**
     * @return array{id: string}|null
     */
    private function threadFor(User $user, string $sendKey): ?array
    {
        $publicId = Thread::query()->withoutVisibilityScope()
            ->where('created_by_user_id', $user->id)
            ->where('send_key', $sendKey)
            ->value('public_id');

        return $publicId === null ? null : ['id' => $publicId];
    }
}
