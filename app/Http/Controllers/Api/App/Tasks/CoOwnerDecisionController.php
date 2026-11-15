<?php

namespace App\Http\Controllers\Api\App\Tasks;

use App\Enums\CoOwnerRequestStatus;
use App\Exceptions\ThreadActionRefused;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\GitHubAccountController;
use App\Http\Requests\Api\App\RequestAppCoOwnerChangesRequest;
use App\Http\Resources\CoOwnerPanelData;
use App\Models\CoOwnerRequest;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\CoOwnerDecisionException;
use App\Services\CoOwner\CoOwnerDecisions;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Decisions on the latest co-owner request of a task's conversation. Every call is time bounded: GitHub calls give up after three seconds and a lock wait after five.
 * Approve stays synchronous and opens the pull request before it answers, which can take up to a minute.
 */
class CoOwnerDecisionController extends Controller
{
    public function __construct(private CoOwnerDecisions $decisions) {}

    public function approve(Request $request, YakTask $task): JsonResponse
    {
        return $this->decide($request, $task, CoOwnerRequestStatus::Approved, function (CoOwnerRequest $coOwnerRequest, User $user): void {
            $this->decisions->approve($coOwnerRequest, $user, isTimeBounded: true);
        });
    }

    public function requestChanges(RequestAppCoOwnerChangesRequest $request, YakTask $task): JsonResponse
    {
        return $this->decide($request, $task, CoOwnerRequestStatus::ChangesRequested, function (CoOwnerRequest $coOwnerRequest, User $user) use ($request): void {
            $this->decisions->requestChanges($coOwnerRequest, $user, $request->validated('feedback'), isTimeBounded: true);
        });
    }

    public function reject(Request $request, YakTask $task): JsonResponse
    {
        return $this->decide($request, $task, CoOwnerRequestStatus::Rejected, function (CoOwnerRequest $coOwnerRequest, User $user): void {
            $this->decisions->reject($coOwnerRequest, $user, isTimeBounded: true);
        });
    }

    public function withdraw(Request $request, YakTask $task): JsonResponse
    {
        return $this->decide($request, $task, CoOwnerRequestStatus::Withdrawn, function (CoOwnerRequest $coOwnerRequest, User $user): void {
            $this->decisions->withdraw($coOwnerRequest, $user, isTimeBounded: true);
        });
    }

    /**
     * A repeat by the person who made this decision answers the same status and does nothing.
     *
     * @param  Closure(CoOwnerRequest, User): void  $decision
     */
    private function decide(Request $request, YakTask $task, CoOwnerRequestStatus $status, Closure $decision): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $coOwnerRequest = CoOwnerPanelData::latestRequest($task) ?? abort(404);

        if ($this->isDecidedBy($coOwnerRequest, $status, $user)) {
            return response()->json(['status' => $status->value]);
        }

        try {
            $decision($coOwnerRequest, $user);
        } catch (CoOwnerDecisionException $exception) {
            if ($exception->reason === CoOwnerDecisionException::ALREADY_RESOLVED && $this->isDecidedBy($coOwnerRequest, $status, $user)) {
                return response()->json(['status' => $status->value]);
            }

            throw $this->refusal($exception);
        }

        return response()->json(['status' => $status->value]);
    }

    /**
     * Reads the request again, so a double tap that lost the race answers like the tap that won.
     */
    private function isDecidedBy(CoOwnerRequest $coOwnerRequest, CoOwnerRequestStatus $status, User $user): bool
    {
        return CoOwnerRequest::whereKey($coOwnerRequest->id)
            ->where('status', $status)
            ->where('decided_by_user_id', $user->id)
            ->exists();
    }

    private function refusal(CoOwnerDecisionException $exception): ThreadActionRefused
    {
        $status = match ($exception->reason) {
            CoOwnerDecisionException::ALREADY_RESOLVED, CoOwnerDecisionException::NOT_LINKED => 409,
            CoOwnerDecisionException::NO_WRITE_ACCESS, CoOwnerDecisionException::NOT_REQUESTER => 403,
            default => 503,
        };

        $details = match (true) {
            $exception->reason === CoOwnerDecisionException::NOT_LINKED => ['connectUrl' => GitHubAccountController::connectUrl()],
            $exception->reason === CoOwnerDecisionException::GITHUB_FAILED && $exception->status !== null => ['githubStatus' => $exception->status],
            default => [],
        };

        $message = match (true) {
            $exception->reason !== CoOwnerDecisionException::GITHUB_FAILED => $exception->getMessage(),
            $exception->status === null => 'Yak could not open the pull request. Try again in a moment.',
            default => "GitHub refused the pull request: {$exception->getMessage()}",
        };

        if ($exception->reason === CoOwnerDecisionException::GITHUB_FAILED && $exception->status === null) {
            report($exception);
        }

        return new ThreadActionRefused($exception->reason, $message, $details, $status);
    }
}
