<?php

namespace App\Http\Controllers\Threads\PullRequests;

use App\Enums\ThreadOwnerSide;
use App\Http\Controllers\Controller;
use App\Jobs\Threads\RetryRepositoryCiJob;
use App\Models\Thread;
use App\Models\ThreadRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A person's request to have Claude fix one repo's failing CI. It does not
 * spend the repo's automatic retry budget.
 */
class AskToFixController extends Controller
{
    public function __invoke(Thread $thread, int $repository): JsonResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);
        Gate::authorize('drive', $thread);

        $row = ThreadRepository::query()->where('thread_id', $thread->id)->whereKey($repository)->first();

        abort_if($row === null || ! $row->isChangesRepo() || ! $row->hasOpenPullRequest(), 404);
        abort_if($thread->owner_side === ThreadOwnerSide::Device, 409, 'Your computer is working on this thread. Hand it back to Yak before asking for a fix.');
        abort_unless($thread->isOpen(), 409, 'This thread is closed.');
        abort_unless($row->ci_status === 'failing', 409, 'CI is not failing on this pull request.');

        RetryRepositoryCiJob::dispatch($row->id, $this->failureOutput($row));

        return response()->json(['ok' => true], 202);
    }

    private function failureOutput(ThreadRepository $row): ?string
    {
        $sections = [];

        foreach ($row->ci_checks ?? [] as $check) {
            if ($check['state'] === 'failing') {
                $sections[] = "## {$check['name']}\n" . ($check['excerpt'] ?? '');
            }
        }

        return $sections === [] ? null : implode("\n\n", $sections);
    }
}
