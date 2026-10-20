<?php

namespace App\Http\Controllers\Tasks;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\RequestCoOwnerChangesRequest;
use App\Http\Resources\CoOwnerPanelData;
use App\Models\CoOwnerRequest;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\CoOwnerDecisionException;
use App\Services\CoOwner\CoOwnerDecisions;
use App\Services\CoOwner\GitHubWriteAccess;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Dashboard decisions on the latest co-owner request of a task's conversation.
 */
class CoOwnerDecisionController extends Controller
{
    public function __construct(private CoOwnerDecisions $decisions) {}

    public function approve(Request $request, YakTask $task): RedirectResponse
    {
        return $this->decide($task, function (CoOwnerRequest $coOwnerRequest) use ($request): string {
            $approved = $this->decisions->approve($coOwnerRequest, $this->user($request));

            return "Approved. PR #{$approved->pullRequestNumber()} is open.";
        });
    }

    public function requestChanges(RequestCoOwnerChangesRequest $request, YakTask $task): RedirectResponse
    {
        return $this->decide($task, function (CoOwnerRequest $coOwnerRequest) use ($request): string {
            $this->decisions->requestChanges($coOwnerRequest, $this->user($request), $request->string('feedback')->toString());

            return 'Feedback sent. Yak is running a follow-up.';
        });
    }

    public function reject(Request $request, YakTask $task): RedirectResponse
    {
        return $this->decide($task, function (CoOwnerRequest $coOwnerRequest) use ($request): string {
            $this->decisions->reject($coOwnerRequest, $this->user($request));

            return 'Rejected. The branch was deleted.';
        });
    }

    public function withdraw(Request $request, YakTask $task): RedirectResponse
    {
        return $this->decide($task, function (CoOwnerRequest $coOwnerRequest) use ($request): string {
            $this->decisions->withdraw($coOwnerRequest, $this->user($request));

            return 'Withdrawn. The branch was deleted.';
        });
    }

    public function checkAccess(Request $request, YakTask $task, GitHubWriteAccess $writeAccess): RedirectResponse
    {
        $coOwnerRequest = CoOwnerPanelData::latestRequest($task);
        abort_if($coOwnerRequest === null, 404);

        $writeAccess->forget($coOwnerRequest->repository, $this->user($request));

        return back();
    }

    /**
     * @param  Closure(CoOwnerRequest): string  $decision  returns the success message
     */
    private function decide(YakTask $task, Closure $decision): RedirectResponse
    {
        $coOwnerRequest = CoOwnerPanelData::latestRequest($task);
        abort_if($coOwnerRequest === null, 404);

        try {
            return back()->with('success', $decision($coOwnerRequest));
        } catch (CoOwnerDecisionException $exception) {
            return back()->withErrors(['coOwner' => [[
                'reason' => $exception->reason,
                'status' => $exception->status,
                'message' => $exception->getMessage(),
            ]]]);
        }
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_if($user === null, 403);

        return $user;
    }
}
