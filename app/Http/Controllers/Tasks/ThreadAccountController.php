<?php

namespace App\Http\Controllers\Tasks;

use App\Enums\ThreadBlockReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\SwitchThreadAccountRequest;
use App\Models\ClaudeAccount;
use App\Models\Thread;
use App\Models\YakTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Lets a person with a task in the thread change the thread's Claude account
 * or model. The next turn restarts the runner for an account change and only
 * sends set_model for a model change.
 */
class ThreadAccountController extends Controller
{
    public function update(SwitchThreadAccountRequest $request, YakTask $task): RedirectResponse
    {
        $thread = $this->participantThread($request, $task);
        $account = ClaudeAccount::query()->findOrFail((int) $request->validated('claude_account_id'));

        abort_unless($account->isSystem() || $account->owner_user_id === $request->user()?->id, 403);

        if (! $account->isUsable()) {
            return back()->with('error', 'That Claude account cannot be used right now.');
        }

        $thread->forceFill([
            'claude_account_id' => $account->id,
            'account_switched_by_user_id' => $request->user()?->id,
            'account_switched_at' => now(),
            'blocked_reason' => in_array($thread->blocked_reason, [ThreadBlockReason::InvalidToken, ThreadBlockReason::UsageLimit, ThreadBlockReason::NoValidAccount], true)
                ? null
                : $thread->blocked_reason,
        ])->save();

        return back()->with('success', "This thread now runs on {$account->name}.");
    }

    public function updateModel(Request $request, YakTask $task): RedirectResponse
    {
        $thread = $this->participantThread($request, $task);
        $thread->forceFill(['model' => $request->validate(['model' => ['required', 'string', 'max:100']])['model']])->save();

        return back()->with('success', 'The model changes with the next turn.');
    }

    private function participantThread(Request $request, YakTask $task): Thread
    {
        $thread = Thread::query()->findOrFail($task->thread_id);

        abort_unless(
            $thread->tasks()->where('started_by_user_id', $request->user()?->id)->exists(),
            403,
        );

        return $thread;
    }
}
