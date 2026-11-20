<?php

namespace App\Http\Controllers\Threads;

use App\DataTransferObjects\ThreadMessageInput;
use App\Enums\MessageDelivery;
use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Threads\SendAgentMessageRequest;
use App\Http\Resources\ThreadRunLogData;
use App\Models\Thread;
use App\Models\ThreadAgent;
use App\Services\Threads\ThreadAgents;
use App\Services\Threads\ThreadMessageSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * What a person can do with the agents and workflows Claude runs in a thread: read an agent's own log,
 * stop one (and the agents it started) or all of them, and ask Claude to pass a message on.
 * The agent is looked up by Claude's task id inside the thread, so another thread's agent answers as missing.
 */
class ThreadAgentController extends Controller
{
    public function log(Request $request, Thread $thread, ThreadAgent $agent): JsonResponse
    {
        abort_unless(Gate::allows('view', $thread), 404);

        return response()->json(ThreadRunLogData::forAgent($thread, $agent, max(0, $request->integer('cursor'))));
    }

    public function stop(Thread $thread, ThreadAgent $agent, ThreadAgents $agents): RedirectResponse
    {
        if (! $agent->isRunning()) {
            return back()->with('error', 'This agent has already finished.');
        }

        $stopped = count($agents->stop($thread, $agent));

        return back()->with('success', $stopped > 1 ? "Stopping the agent and {$stopped} others it started." : 'Stopping the agent.');
    }

    public function stopAll(Thread $thread, ThreadAgents $agents): RedirectResponse
    {
        $stopped = count($agents->stopAll($thread));

        return $stopped === 0
            ? back()->with('error', 'No agents are running.')
            : back()->with('success', $stopped === 1 ? 'Stopping the agent.' : "Stopping {$stopped} agents.");
    }

    /**
     * Claude receives "Pass this to the agent <description> (<task id>): <text>" as a steering message and
     * forwards it with SendMessage; the message is kept against the agent until Claude has.
     */
    public function message(SendAgentMessageRequest $request, Thread $thread, ThreadAgent $agent, ThreadMessageSender $sender): RedirectResponse|JsonResponse
    {
        $user = $request->user() ?? abort(401);
        $text = (string) $request->validated('message');

        if (! $agent->isRunning()) {
            return $this->refuse($request, 'This agent has finished, so it cannot take a message.');
        }

        $record = $agent->messages()->create(['user_id' => $user->id, 'text' => $text]);

        try {
            $sender->send($thread, $user, new ThreadMessageInput(ThreadAgents::steeringText($agent, $text), null, null, null, MessageDelivery::Steer, null));
        } catch (ThreadActionRefused $refusal) {
            $record->delete();

            return $this->refuse($request, $refusal->getMessage());
        }

        return $request->expectsJson()
            ? response()->json(['id' => $record->id, 'text' => $record->text, 'sentAt' => $record->created_at?->toIso8601String(), 'forwardedAt' => null])
            : back()->with('success', 'Sent to Claude, who passes it on.');
    }

    private function refuse(Request $request, string $reason): RedirectResponse|JsonResponse
    {
        return $request->expectsJson() ? response()->json(['message' => $reason], 422) : back()->with('error', $reason);
    }
}
