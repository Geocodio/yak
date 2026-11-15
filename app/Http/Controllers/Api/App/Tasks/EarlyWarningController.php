<?php

namespace App\Http\Controllers\Api\App\Tasks;

use App\Channels\Slack\CoOwnerMessenger;
use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\App\TagAppCoOwnerRequest;
use App\Http\Resources\EarlyWarningData;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\CoOwnerTagging;
use App\Services\CoOwner\CoOwnerTaggingException;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The requester's answer to the early warning from the phone: tag a co-owner or decline to, and the people who can be tagged.
 * Every GitHub call is time bounded and a lock wait ends after five seconds.
 */
class EarlyWarningController extends Controller
{
    public function __construct(private CoOwnerTagging $tagging, private CoOwnerMessenger $messenger) {}

    public function candidates(Request $request, YakTask $task): JsonResponse
    {
        return response()->json(EarlyWarningData::candidatesWithin($task->rootTask(), $request->user()));
    }

    public function tag(TagAppCoOwnerRequest $request, YakTask $task): JsonResponse
    {
        $coOwner = User::findOrFail($request->integer('user_id'));

        return $this->answer($request, $task, 'tagged', $coOwner->id, fn (User $actor) => $this->tagging->tag($task->rootTask(), $coOwner, $actor, isTimeBounded: true));
    }

    public function decline(Request $request, YakTask $task): JsonResponse
    {
        return $this->answer($request, $task, 'declined', null, fn (User $actor) => $this->tagging->decline($task->rootTask(), $actor, isTimeBounded: true));
    }

    /**
     * A repeat of the answer already given answers the same status and does nothing. The requester check comes first, so nobody else learns the state.
     *
     * @param  Closure(User): void  $answer
     */
    private function answer(Request $request, YakTask $task, string $status, ?int $coOwnerId, Closure $answer): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();
        $root = $task->rootTask();

        if (EarlyWarningData::isRequester($root, $actor)) {
            if ($this->isAnswered($root, $status, $coOwnerId)) {
                return response()->json(['status' => $status]);
            }

            if (in_array($root->fresh()?->early_warning['state'] ?? 'shown', ['tagged', 'declined'], true)) {
                throw new ThreadActionRefused(CoOwnerTaggingException::ALREADY_DECIDED, CoOwnerTaggingException::alreadyDecided()->getMessage(), [], 409);
            }
        }

        try {
            $answer($actor);
        } catch (CoOwnerTaggingException $exception) {
            throw new ThreadActionRefused($exception->reason, $exception->getMessage(), [], match ($exception->reason) {
                CoOwnerTaggingException::NOT_REQUESTER => 403,
                CoOwnerTaggingException::ACCESS_CHECK_FAILED => 503,
                default => 409,
            });
        }

        $fresh = $root->fresh() ?? $root;
        defer(fn () => $this->messenger->updateEarlyWarning($fresh));

        return response()->json(['status' => $status]);
    }

    private function isAnswered(YakTask $root, string $status, ?int $coOwnerId): bool
    {
        $fresh = $root->fresh();

        return ($fresh?->early_warning['state'] ?? null) === $status
            && ($coOwnerId === null || $fresh?->tagged_co_owner_user_id === $coOwnerId);
    }
}
