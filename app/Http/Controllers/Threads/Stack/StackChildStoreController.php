<?php

namespace App\Http\Controllers\Threads\Stack;

use App\Enums\ThreadKind;
use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Threads\Stack\StoreStackChildRequest;
use App\Models\Thread;
use App\Models\User;
use App\Services\Stacks\StackActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * "Add sub-thread" in the Stack panel: the same spawn the tool call does.
 */
class StackChildStoreController extends Controller
{
    public function __invoke(StoreStackChildRequest $request, Thread $thread, StackActions $actions): JsonResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);
        Gate::authorize('drive', $thread);

        $user = $request->user();
        assert($user instanceof User);

        try {
            $child = $actions->addChild(
                $thread,
                $user,
                (string) $request->validated('name'),
                (string) $request->validated('brief'),
                ThreadKind::from((string) $request->validated('kind')),
                $request->validated('model') ?: null,
                null,
            );
        } catch (ThreadActionRefused $refusal) {
            return response()->json(['message' => $refusal->getMessage(), 'errors' => ['stack' => [$refusal->getMessage()]]], 422);
        }

        return response()->json(['id' => $child->public_id, 'name' => $child->name], 201);
    }
}
