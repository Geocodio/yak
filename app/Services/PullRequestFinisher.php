<?php

namespace App\Services;

use App\Channels\Linear\NotificationDriver as LinearNotificationDriver;
use App\Channels\Linear\SessionPlanStage;
use App\Channels\Linear\WorkflowStateResolver;
use App\Enums\NotificationType;
use App\Enums\TaskStatus;
use App\Jobs\SendNotificationJob;
use App\Models\LinearOauthConnection;
use App\Models\YakTask;
use Illuminate\Support\Facades\Log;

/**
 * What happens once a task's pull request is open: the task succeeds, Linear
 * hears about it, and the requester gets the PR link.
 */
class PullRequestFinisher
{
    public function finish(YakTask $task, ?string $messagePrefix = null): void
    {
        $prUrl = $task->pr_url ?? '';

        TaskLogger::info($task, 'PR created', ['pr_url' => $prUrl]);

        $task->update([
            'status' => TaskStatus::Success,
            'completed_at' => now(),
        ]);

        if ($task->source === 'linear') {
            $this->reportPullRequestToLinear($task, $prUrl);
            $this->moveLinearToInReview($task);
        }

        // The result is queued after the Linear action above, so Linear's
        // last activity is the `response` that completes the session.
        $this->notifyUnlessGitHubStarted($task, NotificationType::Result, "{$messagePrefix}PR created: {$prUrl}");

        TaskLogger::info($task, 'Task completed');
    }

    /**
     * GitHub-started work already has its pull request comment from
     * CreatePullRequestJob, and GitHub notifies the people on that pull
     * request. A second comment for the same event would notify them twice.
     */
    public function notifyUnlessGitHubStarted(YakTask $task, NotificationType $type, string $message): void
    {
        if ($task->source === 'github') {
            return;
        }

        SendNotificationJob::dispatch($task, $type, $message);
    }

    private function reportPullRequestToLinear(YakTask $task, string $prUrl): void
    {
        $linear = app(LinearNotificationDriver::class);
        $sessionId = (string) $task->linear_agent_session_id;

        if ($prUrl !== '') {
            $isFollowUp = $task->parent_task_id !== null;
            $linear->postAction($sessionId, $isFollowUp ? 'Pushed changes' : 'Opened pull request', $prUrl);

            if (! $isFollowUp) {
                $linear->addSessionExternalUrl($sessionId, 'Pull request', $prUrl);
            }
        }

        $linear->syncSessionPlan($task, SessionPlanStage::PullRequestOpened);
    }

    /**
     * Move the Linear issue to its review state once the PR is open. An
     * explicit `in_review_state_id` wins; otherwise, when the connection
     * allows Yak to move issues, the team's "review" state is looked up.
     * A failed move never affects the CI result flow.
     */
    private function moveLinearToInReview(YakTask $task): void
    {
        try {
            $linear = app(LinearNotificationDriver::class);
            $stateId = (string) config('yak.channels.linear.in_review_state_id');

            if ($stateId === '') {
                $connection = LinearOauthConnection::active();
                if ($connection === null || ! $connection->move_issues_to_started_state) {
                    return;
                }

                $stateId = (string) app(WorkflowStateResolver::class)
                    ->inReviewForIssue($linear->resolveLinearIssueId($task));
            }

            if ($stateId !== '') {
                $linear->setIssueState($task, $stateId);
            }
        } catch (\Throwable $e) {
            Log::warning('Could not move Linear issue to review', [
                'task_id' => $task->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
