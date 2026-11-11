<?php

namespace App\Http\Controllers\Threads\Stack;

use App\Enums\ThreadKind;
use App\Exceptions\StackRuleViolation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Threads\Stack\StoreStackChildRequest;
use App\Jobs\StartChildThreadJob;
use App\Models\Thread;
use App\Services\Stacks\ChildSpawner;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * "Add sub-thread" in the Stack panel: the same spawn the tool call does.
 */
class StackChildStoreController extends Controller
{
    public function __invoke(StoreStackChildRequest $request, Thread $thread, ChildSpawner $spawner): JsonResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);
        Gate::authorize('drive', $thread);

        try {
            $child = $spawner->spawn(
                $thread,
                (string) $request->validated('name'),
                (string) $request->validated('brief'),
                ThreadKind::from((string) $request->validated('kind')),
                $request->validated('model') ?: null,
            );
        } catch (StackRuleViolation $violation) {
            return response()->json(['message' => $violation->getMessage(), 'errors' => ['stack' => [$violation->getMessage()]]], 422);
        }

        StartChildThreadJob::dispatch($child->id);

        return response()->json(['id' => $child->id, 'name' => $child->name], 201);
    }
}
