<?php

namespace App\Http\Controllers\Api\App\Threads\Preview;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Threads\Preview\ThreadPreviewActivity;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Throwable;

/** Keeps the sandbox awake while the app shows the preview. */
class PreviewHeartbeatController extends Controller
{
    public function __invoke(Thread $thread, ThreadPreviewActivity $activity): Response
    {
        abort_unless(Gate::allows('view', $thread), 404);

        try {
            $activity->record($thread);
        } catch (Throwable $exception) {
            report($exception);
        }

        return response()->noContent();
    }
}
