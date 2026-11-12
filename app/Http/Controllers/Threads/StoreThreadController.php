<?php

namespace App\Http\Controllers\Threads;

use App\Enums\TaskMode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Threads\StoreThreadRequest;
use App\Models\TaskAttachment;
use App\Services\Threads\ThreadStarter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class StoreThreadController extends Controller
{
    public function __invoke(StoreThreadRequest $request, ThreadStarter $starter): RedirectResponse
    {
        $validated = $request->validated();

        try {
            $thread = $starter->start(
                $request->user() ?? abort(403),
                $validated['repo'],
                TaskMode::from($validated['mode']),
                $validated['description'],
                isset($validated['claude_account_id']) ? (int) $validated['claude_account_id'] : null,
                $validated['model'] ?? null,
                TaskAttachment::storeFromRequest($request),
                $validated['repositories'] ?? [],
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['claude_account_id' => $exception->getMessage()]);
        }

        // With extra repositories the dialog stays open to show per-repo progress.
        if (! empty($validated['repositories'])) {
            return back()->with('startedThreadId', $thread->id);
        }

        return redirect()->route('threads.show', $thread);
    }
}
