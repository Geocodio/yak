<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Enums\ThreadKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreAppStackChildRequest;
use App\Http\Resources\StackData;
use App\Models\Thread;
use App\Services\Stacks\StackActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The app's stack routes: the Overview reads the stack from a root or from any of its sub-threads.
 */
class StackController extends Controller
{
    public function show(Request $request, Thread $thread): JsonResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        return response()->json(StackData::for($thread, $request->user()));
    }

    /**
     * Adds a sub-thread; the same send key answers the same child.
     */
    public function storeChild(StoreAppStackChildRequest $request, Thread $thread, StackActions $actions): JsonResponse
    {
        $child = $actions->addChild(
            $thread,
            $request->user() ?? abort(403),
            (string) $request->validated('name'),
            (string) $request->validated('brief'),
            ThreadKind::from((string) $request->validated('kind')),
            $request->validated('model') ?: null,
            (string) $request->validated('send_key'),
        );

        return response()->json(['id' => $child->public_id, 'name' => $child->name], 201);
    }
}
