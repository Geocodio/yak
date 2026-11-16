<?php

namespace App\Http\Controllers\Api\App\Threads\Preview;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppPreviewStatusData;
use App\Models\Thread;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class PreviewStatusController extends Controller
{
    public function __invoke(Thread $thread): JsonResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        return response()->json(AppPreviewStatusData::for($thread));
    }
}
