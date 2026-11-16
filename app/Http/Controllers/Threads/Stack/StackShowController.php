<?php

namespace App\Http\Controllers\Threads\Stack;

use App\Http\Controllers\Controller;
use App\Http\Resources\StackData;
use App\Models\Thread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The Stack panel's data, from the parent's page or any child's page.
 */
class StackShowController extends Controller
{
    public function __invoke(Request $request, Thread $thread): JsonResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        return response()->json(StackData::for($thread, $request->user()));
    }
}
