<?php

namespace App\Services\CoOwner;

use App\Channels\GitHub\AppService;
use App\Enums\CoOwnerRequestStatus;
use App\Enums\TaskStatus;
use App\Jobs\CreatePullRequestJob;
use App\Jobs\SendCoOwnerMessagesJob;
use App\Models\CoOwnerRequest;
use App\Models\Repository;
use App\Models\User;
use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;
use App\Services\PullRequestFinisher;
use App\Services\TaskLogger;
use Closure;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves a held change. Every decision locks the task row, then the request
 * row (the same order everywhere), and applies only while the request is
 * still Pending, so the first of two racing decisions wins. Nothing holds a
 * lock across a GitHub call.
 */
class CoOwnerDecisions
{
    public function __construct(
        private GitHubWriteAccess $writeAccess,
        private TaskRequester $requesters,
        private AppService $github,
        private FollowUpTaskFactory $followUps,
        private PullRequestFinisher $finisher,
    ) {}

    /**
     * Opens the PR once a co-owner with write access approves.
     */
    public function approve(CoOwnerRequest $request, User $approver): YakTask
    {
        $repository = $this->repositoryFor($request);
        $this->requireWriteAccess($repository, $approver);

        $previousResponsible = [null, null];
        $task = $this->claim($request, $approver, CoOwnerRequestStatus::Approved, function (YakTask $task) use ($approver, &$previousResponsible): void {
            $previousResponsible = [$task->responsible_user_id, $task->responsible_name];
            $task->update(['responsible_user_id' => $approver->id, 'responsible_name' => $approver->name]);
        });

        $request->refresh();

        try {
            $isLargeChange = $request->additions + $request->deletions > $repository->settings()->largeChangeLines();
            CreatePullRequestJob::dispatchSync($task, $isLargeChange, $request);
            $task->refresh();
        } catch (Throwable $exception) {
            // Once the PR is open (its URL is saved) the approval stands.
            if (YakTask::whereKey($task->id)->value('pr_url') === null) {
                $this->revertApproval($request, $task, $approver, $previousResponsible);

                throw $exception instanceof RequestException
                    ? CoOwnerDecisionException::githubFailed($exception->response->status(), (string) ($exception->response->json('message') ?? $exception->getMessage()))
                    : CoOwnerDecisionException::githubFailed(null, $exception->getMessage());
            }

            report($exception);
            $task->refresh();
        }

        $this->finisher->finish($task, "Approved by {$approver->name}. ");
        $this->announce($request);

        return $task;
    }

    /**
     * Sends the feedback back as a follow-up; the follow-up goes through the gate again.
     */
    public function requestChanges(CoOwnerRequest $request, User $user, string $feedback): YakTask
    {
        $this->requireWriteAccess($this->repositoryFor($request), $user);

        $followUp = null;
        $this->claim($request, $user, CoOwnerRequestStatus::ChangesRequested, function (YakTask $task) use ($request, $user, $feedback, &$followUp): void {
            $request->update(['feedback' => $feedback]);
            $followUp = $this->followUps->create($task, $feedback, (string) $task->source, $user->name);

            if ($followUp === null) {
                throw CoOwnerDecisionException::alreadyResolved();
            }
        });

        $this->announce($request);

        /** @var YakTask $followUp */
        return $followUp;
    }

    public function reject(CoOwnerRequest $request, User $user): void
    {
        $this->requireWriteAccess($this->repositoryFor($request), $user);
        $this->end($request, CoOwnerRequestStatus::Rejected, $user);
    }

    public function withdraw(CoOwnerRequest $request, User $requester): void
    {
        $task = YakTask::findOrFail($request->task_id);

        if ($this->requesters->for($task)?->is($requester) !== true) {
            throw CoOwnerDecisionException::notRequester();
        }

        $this->end($request, CoOwnerRequestStatus::Withdrawn, $requester);
    }

    /**
     * A stop in Linear ends the held conversation as a withdraw by the system.
     */
    public function withdrawForStop(YakTask $heldTask): void
    {
        $request = $heldTask->openCoOwnerRequest();

        if ($request === null) {
            throw CoOwnerDecisionException::alreadyResolved();
        }

        $this->end($request, CoOwnerRequestStatus::Withdrawn, null);
    }

    /**
     * The branch stays: the work is still there for a later decision.
     */
    public function expire(CoOwnerRequest $request): void
    {
        $this->claim($request, null, CoOwnerRequestStatus::Expired, function (YakTask $task): void {
            $task->update(['status' => TaskStatus::Expired, 'completed_at' => now()]);
        });

        $this->announce($request);
    }

    /**
     * A follow-up started on a held task: close the open request, if any, and
     * let the task rest as Success with no PR.
     */
    public function supersede(YakTask $heldTask): void
    {
        $request = null;

        DB::transaction(function () use ($heldTask, &$request): void {
            $task = YakTask::whereKey($heldTask->id)->lockForUpdate()->firstOrFail();

            if ($task->status !== TaskStatus::AwaitingCoOwner) {
                return;
            }

            // An approval in flight finishes on its own; leave the task alone.
            if (CoOwnerRequest::where('task_id', $task->id)->where('status', CoOwnerRequestStatus::Approved)->exists()) {
                return;
            }

            $request = CoOwnerRequest::where('task_id', $task->id)->where('status', CoOwnerRequestStatus::Pending)->lockForUpdate()->latest('id')->first();
            $request?->update(['status' => CoOwnerRequestStatus::Superseded, 'decided_at' => now()]);
            $task->update(['status' => TaskStatus::Success, 'completed_at' => now()]);
            $heldTask->refresh();
        });

        if ($request !== null) {
            $this->announce($request);
        }
    }

    /** Reject and withdraw: the task is cancelled and its branch deleted. */
    private function end(CoOwnerRequest $request, CoOwnerRequestStatus $status, ?User $by): void
    {
        $task = $this->claim($request, $by, $status, function (YakTask $task): void {
            $task->update(['status' => TaskStatus::Cancelled, 'completed_at' => now()]);
        });

        $this->deleteBranch($request, $task);
        $this->announce($request);
    }

    /**
     * Marks a Pending request resolved under the locks and runs $apply on the
     * locked task, all in one transaction.
     *
     * @param  Closure(YakTask): void  $apply
     */
    private function claim(CoOwnerRequest $request, ?User $by, CoOwnerRequestStatus $status, Closure $apply): YakTask
    {
        return DB::transaction(function () use ($request, $by, $status, $apply): YakTask {
            $task = YakTask::whereKey($request->task_id)->lockForUpdate()->firstOrFail();
            $locked = CoOwnerRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== CoOwnerRequestStatus::Pending || $task->status !== TaskStatus::AwaitingCoOwner) {
                throw CoOwnerDecisionException::alreadyResolved();
            }

            $locked->update(['status' => $status, 'decided_by_user_id' => $by?->id, 'decided_at' => now()]);
            $request->setRawAttributes($locked->getAttributes(), true);

            $apply($task);

            TaskLogger::info($task, 'Co-owner request resolved', ['co_owner_request_id' => $request->id, 'status' => $status->value, 'decided_by' => $by?->id]);

            return $task;
        });
    }

    /**
     * Puts a failed approval back to Pending, but only while it is still the
     * approval this call made.
     *
     * @param  array{0: int|null, 1: string|null}  $previousResponsible
     */
    private function revertApproval(CoOwnerRequest $request, YakTask $task, User $approver, array $previousResponsible): void
    {
        DB::transaction(function () use ($request, $task, $approver, $previousResponsible): void {
            $reverted = CoOwnerRequest::whereKey($request->id)
                ->where('status', CoOwnerRequestStatus::Approved)
                ->where('decided_by_user_id', $approver->id)
                ->update(['status' => CoOwnerRequestStatus::Pending, 'decided_by_user_id' => null, 'decided_at' => null]);

            if ($reverted > 0) {
                YakTask::whereKey($task->id)->update(['responsible_user_id' => $previousResponsible[0], 'responsible_name' => $previousResponsible[1]]);
            }
        });
    }

    private function deleteBranch(CoOwnerRequest $request, YakTask $task): void
    {
        if ($task->branch_name === null) {
            return;
        }

        try {
            $this->github->deleteBranch((int) config('yak.channels.github.installation_id'), $this->repositoryFor($request)->github_full_name, $task->branch_name);
        } catch (Throwable $exception) {
            Log::channel('yak')->warning('Could not delete the branch of a resolved co-owner request', ['task_id' => $task->id, 'error' => $exception->getMessage()]);
        }
    }

    private function repositoryFor(CoOwnerRequest $request): Repository
    {
        return Repository::findOrFail($request->repository_id);
    }

    private function requireWriteAccess(Repository $repository, User $user): void
    {
        match ($this->writeAccess->check($repository, $user)) {
            'write' => null,
            'unlinked' => throw CoOwnerDecisionException::notLinked(),
            'error' => throw CoOwnerDecisionException::accessCheckFailed(),
            default => throw CoOwnerDecisionException::noWriteAccess($repository->github_full_name),
        };
    }

    private function announce(CoOwnerRequest $request): void
    {
        try {
            SendCoOwnerMessagesJob::dispatch($request->fresh() ?? $request, 'resolved')->afterCommit();
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
