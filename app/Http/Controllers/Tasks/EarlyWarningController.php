<?php

namespace App\Http\Controllers\Tasks;

use App\Channels\Slack\CoOwnerMessenger;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\TagCoOwnerRequest;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\CoOwnerTagging;
use App\Services\CoOwner\CoOwnerTaggingException;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The requester's dashboard answer to the early warning: tag a co-owner or decline to.
 */
class EarlyWarningController extends Controller
{
    public function __construct(private CoOwnerTagging $tagging, private CoOwnerMessenger $messenger) {}

    public function tag(TagCoOwnerRequest $request, YakTask $task): RedirectResponse
    {
        $coOwner = User::findOrFail($request->integer('user_id'));

        return $this->answer(fn (User $actor) => $this->tagging->tag($task->rootTask(), $coOwner, $actor), $request, $task);
    }

    public function decline(Request $request, YakTask $task): RedirectResponse
    {
        return $this->answer(fn (User $actor) => $this->tagging->decline($task->rootTask(), $actor), $request, $task);
    }

    /**
     * @param  Closure(User): void  $answer
     */
    private function answer(Closure $answer, Request $request, YakTask $task): RedirectResponse
    {
        $actor = $request->user();
        abort_if($actor === null, 403);

        try {
            $answer($actor);
        } catch (CoOwnerTaggingException $exception) {
            return back()->withErrors(['earlyWarning' => [['reason' => $exception->reason, 'message' => $exception->getMessage()]]]);
        }

        $root = $task->rootTask();
        $this->messenger->updateEarlyWarning($root->fresh() ?? $root);

        return back();
    }
}
