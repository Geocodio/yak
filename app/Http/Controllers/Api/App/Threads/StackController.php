<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Enums\ThreadKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\StoreAppStackChildRequest;
use App\Http\Requests\Api\App\UpdateAppStackLimitRequest;
use App\Http\Resources\StackData;
use App\Models\Thread;
use App\Models\User;
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
            $this->person($request),
            (string) $request->validated('name'),
            (string) $request->validated('brief'),
            ThreadKind::from((string) $request->validated('kind')),
            $request->validated('model') ?: null,
            (string) $request->validated('send_key'),
            $request->validated('effort'),
        );

        return response()->json(['id' => $child->public_id, 'name' => $child->name], 201);
    }

    /**
     * Stops every turn of the stack in a job; asking again while a turn still runs queues the job again.
     */
    public function stop(Request $request, Thread $thread, StackActions $actions): JsonResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);
        Gate::authorize('drive', $thread->rootThread());

        $actions->stop($thread);

        return response()->json(['status' => 'stopping']);
    }

    public function limit(UpdateAppStackLimitRequest $request, Thread $thread, StackActions $actions): JsonResponse
    {
        $actions->setLimit($thread, (int) $request->validated('limit'));

        return response()->json(['status' => 'updated', 'limit' => StackData::limit($thread->rootThread()->refresh())]);
    }

    public function takeOver(Request $request, Thread $thread, StackActions $actions): JsonResponse
    {
        $this->authorizeChild($thread);
        $actions->takeOver($thread, $this->person($request));

        return response()->json(['status' => 'taken_over']);
    }

    public function split(Request $request, Thread $thread, StackActions $actions): JsonResponse
    {
        $this->authorizeChild($thread);
        $actions->split($thread, $this->person($request));

        return response()->json(['status' => 'split_requested']);
    }

    public function drop(Request $request, Thread $thread, StackActions $actions): JsonResponse
    {
        $this->authorizeChild($thread);
        $actions->drop($thread, $this->person($request));

        return response()->json(['status' => 'dropped']);
    }

    private function authorizeChild(Thread $thread): void
    {
        abort_unless(Gate::allows('view', $thread) && $thread->isChild(), 404);
        Gate::authorize('drive', $thread);
    }

    private function person(Request $request): User
    {
        $user = $request->user();
        assert($user instanceof User);

        return $user;
    }
}
