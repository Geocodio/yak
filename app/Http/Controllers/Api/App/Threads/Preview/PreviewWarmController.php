<?php

namespace App\Http\Controllers\Api\App\Threads\Preview;

use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Threads\Preview\ThreadPreviewActivity;
use App\Services\Threads\Preview\ThreadPreviewWaker;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Throwable;

use function Illuminate\Support\defer;

/**
 * Answers at once; the wake runs after the response. The state comes from status.
 */
class PreviewWarmController extends Controller
{
    /** Matches the window of the web's ThreadPreviewWarmController, so a web warm and an app warm share one throttle. */
    private const THROTTLE_SECONDS = 5;

    public function __invoke(Thread $thread, ThreadPreviewActivity $activity): Response
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);

        try {
            if (! Cache::add("thread-preview:warm:{$thread->id}", true, self::THROTTLE_SECONDS)) {
                return response()->noContent();
            }

            $activity->record($thread);
        } catch (Throwable $exception) {
            report($exception);

            return response()->noContent();
        }

        defer(function () use ($thread): void {
            try {
                app(ThreadPreviewWaker::class)->wake($thread, 0);
            } catch (Throwable $exception) {
                report($exception);
            }
        }, "thread-preview-warm:{$thread->id}");

        return response()->noContent();
    }
}
