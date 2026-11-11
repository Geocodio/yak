<?php

namespace App\Http\Controllers\Threads\PullRequests;

use App\Http\Controllers\Controller;
use App\Http\Requests\Threads\ReorderMergeOrderRequest;
use App\Models\Thread;
use App\Services\Threads\PullRequests\MergeOrder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Saves the suggested merge order of a thread's pull requests.
 */
class MergeOrderController extends Controller
{
    public function __invoke(ReorderMergeOrderRequest $request, Thread $thread, MergeOrder $mergeOrder): JsonResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);
        Gate::authorize('drive', $thread);

        /** @var list<int> $order */
        $order = array_map('intval', $request->validated('order'));
        $mergeOrder->set($thread, $order);

        return response()->json(['ok' => true]);
    }
}
