<?php

namespace App\Http\Controllers\Threads\PullRequests;

use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Models\Thread;
use App\Services\Threads\PullRequests\AskToFix;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * A person's request to have Claude fix one repo's failing CI. It does not
 * spend the repo's automatic retry budget.
 */
class AskToFixController extends Controller
{
    public function __invoke(Thread $thread, int $repository, AskToFix $askToFix): JsonResponse
    {
        // A hidden thread answers exactly like a missing one.
        abort_unless(Gate::allows('view', $thread), 404);
        Gate::authorize('drive', $thread);

        try {
            $askToFix->request($thread, $repository);
        } catch (ModelNotFoundException) {
            abort(404);
        } catch (ThreadActionRefused $refused) {
            abort(409, $refused->getMessage());
        }

        return response()->json(['ok' => true], 202);
    }
}
