<?php

namespace App\Http\Controllers\Threads;

use App\Enums\TaskMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Threads\StoreThreadRequest;
use App\Models\TaskAttachment;
use App\Services\Threads\ThreadAccountUnavailable;
use App\Services\Threads\ThreadStarter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Throwable;

class StoreThreadController extends Controller
{
    public function __invoke(StoreThreadRequest $request, ThreadStarter $starter): RedirectResponse
    {
        $validated = $request->validated();

        $attachments = TaskAttachment::storeFromRequest($request);

        try {
            $thread = $starter->start(
                $request->user() ?? abort(403),
                $validated['repo'],
                TaskMode::from($validated['mode']),
                $validated['description'],
                isset($validated['claude_account_id']) ? (int) $validated['claude_account_id'] : null,
                $validated['model'] ?? null,
                $attachments,
                $validated['repositories'] ?? [],
            );
        } catch (Throwable $exception) {
            TaskAttachment::discard($attachments);

            throw $exception instanceof ThreadAccountUnavailable
                ? ValidationException::withMessages(['claude_account_id' => $exception->getMessage()])
                : $exception;
        }

        // With extra repositories the dialog stays open to show per-repo progress.
        if (! empty($validated['repositories'])) {
            return back()->with('startedThreadId', $thread->public_id);
        }

        return redirect()->route('threads.show', $thread);
    }
}
