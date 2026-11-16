<?php

namespace App\Http\Controllers\Threads;

use App\Http\Controllers\Controller;
use App\Http\Resources\ThreadEnvironmentData;
use App\Models\Thread;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ThreadEnvironmentController extends Controller
{
    public function show(Request $request, Thread $thread): JsonResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);

        return response()->json(ThreadEnvironmentData::build($thread, $request->user()));
    }
}
