<?php

namespace App\Http\Controllers\Api\App\Threads;

use App\Http\Controllers\Controller;
use App\Http\Resources\ThreadAgentsData;
use App\Models\Thread;
use App\Models\ThreadAgent;
use App\Services\Threads\ThreadAgents;
use Illuminate\Http\JsonResponse;

/**
 * "Stop all" for the app: the web's stop-all route, answering the agents that still run instead of a redirect.
 */
class ThreadAgentController extends Controller
{
    public function stopAll(Thread $thread, ThreadAgents $agents): JsonResponse
    {
        // With the subagents flag off there are no agents to stop, as on the web.
        abort_unless(ThreadAgent::isEnabled(), 404);

        $stopped = count($agents->stopAll($thread));

        return response()->json(['status' => $stopped === 0 ? 'idle' : 'stopping', 'stopped' => $stopped, 'agents' => ThreadAgentsData::running($thread)]);
    }
}
