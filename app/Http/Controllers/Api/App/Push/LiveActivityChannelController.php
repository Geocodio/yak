<?php

namespace App\Http\Controllers\Api\App\Push;

use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Push\Apns\ApnsConfig;
use App\Services\Push\Apns\PushRefusals;
use App\Services\Push\LiveActivities\LiveActivityChannels;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class LiveActivityChannelController extends Controller
{
    /**
     * Returns the thread's broadcast channel, creating it at Apple when the thread has none.
     */
    public function __invoke(Request $request, Thread $thread, ApnsConfig $config, LiveActivityChannels $channels): JsonResponse
    {
        // A hidden thread answers exactly like a missing one, before any push check.
        abort_unless(Gate::allows('view', $thread), 404);

        if (! $config->isConfigured()) {
            throw PushRefusals::unavailable();
        }

        if ($thread->closed_at !== null) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, 'This thread is closed.');
        }

        return response()->json(['channel' => $channels->ensureFor($thread, repeatAfterRenewal: false)]);
    }
}
