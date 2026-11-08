<?php

namespace App\Http\Controllers\Tasks;

use App\Enums\TaskRunKind;
use App\Exceptions\NoTurnToContinue;
use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Models\YakTask;
use App\Services\Threads\ThreadTurns;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Starts a new turn on a thread whose last turn the runaway guard stopped.
 * The new turn has its own counters and clock.
 */
class ContinueTurnController extends Controller
{
    public function __invoke(Request $request, YakTask $task, ThreadTurns $turns): RedirectResponse
    {
        $thread = $task->thread_id === null ? null : Thread::query()->find($task->thread_id);

        if ($thread === null) {
            return $this->nothingToContinue($task);
        }

        // ThreadTurns::start checks that the last turn was stopped and the lock is free
        // under the thread's row lock, so a double click cannot start two turns.
        try {
            $run = $turns->start(
                $task,
                TaskRunKind::FollowUp,
                'Continue from where you stopped.',
                (string) $thread->append_system_prompt,
                $request->user()?->id,
                (string) $thread->sandbox_container,
                continuesStoppedTurn: true,
            );
        } catch (NoTurnToContinue) {
            return $this->nothingToContinue($task);
        }

        if ($run === null) {
            return redirect()->route('tasks.show', $task)->with('error', 'The thread is blocked on its Claude account. Switch the account to continue.');
        }

        return redirect()->route('tasks.show', $task)->with('success', 'Yak is continuing.');
    }

    private function nothingToContinue(YakTask $task): RedirectResponse
    {
        return redirect()->route('tasks.show', $task)->with('error', 'There is no stopped turn to continue.');
    }
}
