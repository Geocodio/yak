<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Enums\TaskMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreAppThreadRequest;
use App\Http\Resources\NewThreadData;
use App\Models\TaskAttachment;
use App\Services\ClaudeAccounts\ClaudeModelCatalog;
use App\Services\Threads\SendKeys;
use App\Services\Threads\ThreadAccountUnavailable;
use App\Services\Threads\ThreadStarter;
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

        $result = $sendKeys->once("thread-create:{$user->id}:{$validated['send_key']}", function () use ($request, $starter, $user, $validated): array {
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
                );
            } catch (Throwable $exception) {
                TaskAttachment::discard($attachments);

                throw $exception instanceof ThreadAccountUnavailable
                    ? ValidationException::withMessages(['claude_account_id' => $exception->getMessage()])
                    : $exception;
            }

            return ['id' => $thread->public_id];
        });

        return response()->json($result, 201);
    }
}
