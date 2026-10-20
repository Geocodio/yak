<?php

namespace App\Jobs;

use App\Channels\Slack\CoOwnerBlocks;
use App\Channels\Slack\CoOwnerMessenger;
use App\Channels\Slack\SlackText;
use App\Http\Controllers\Settings\GitHubAccountController;
use App\Models\CoOwnerRequest;
use App\Models\User;
use App\Services\CoOwner\CoOwnerDecisionException;
use App\Services\CoOwner\CoOwnerDecisions;
use App\Services\CoOwner\GitHubWriteAccess;
use App\Services\CoOwner\SlackUserResolver;
use App\Services\CoOwner\TaskRequester;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Applies an Approve, Reject or Withdraw click from Slack. Refusals and
 * failures go back through the click's `response_url` as an ephemeral message.
 * `$action` is one of the CoOwnerBlocks action ids.
 */
class ApplyCoOwnerDecisionJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $requestId,
        public readonly string $slackUserId,
        public readonly string $action,
        public readonly string $responseUrl,
    ) {
        $this->onQueue('default');
    }

    public function handle(
        SlackUserResolver $slackUsers,
        CoOwnerDecisions $decisions,
        CoOwnerMessenger $messenger,
        TaskRequester $requesters,
    ): void {
        $request = CoOwnerRequest::find($this->requestId);

        if ($request === null) {
            return;
        }

        $user = $slackUsers->userFor($this->slackUserId);

        try {
            if ($user === null) {
                throw $this->action === CoOwnerBlocks::WITHDRAW_ACTION_ID
                    ? CoOwnerDecisionException::notRequester()
                    : CoOwnerDecisionException::notLinked();
            }

            match ($this->action) {
                CoOwnerBlocks::APPROVE_ACTION_ID => $decisions->approve($request, $user),
                CoOwnerBlocks::REJECT_ACTION_ID => $decisions->reject($request, $user),
                CoOwnerBlocks::WITHDRAW_ACTION_ID => $decisions->withdraw($request, $user),
                default => throw new \InvalidArgumentException("Unknown co-owner action {$this->action}"),
            };
        } catch (CoOwnerDecisionException $exception) {
            $this->refuse($exception, $request, $user, $messenger, $requesters);
        }
    }

    private function refuse(CoOwnerDecisionException $exception, CoOwnerRequest $request, ?User $user, CoOwnerMessenger $messenger, TaskRequester $requesters): void
    {
        $repository = SlackText::escape($request->repository->github_full_name);

        match ($exception->reason) {
            CoOwnerDecisionException::ALREADY_RESOLVED => $this->showResolved($request, $messenger),
            CoOwnerDecisionException::NOT_LINKED => $messenger->ephemeral(
                $this->responseUrl,
                "Connect GitHub to approve. Yak checks you have write access to {$repository}.",
                $this->connectBlocks($request, "Connect GitHub to approve. Yak checks you have write access to {$repository}."),
            ),
            CoOwnerDecisionException::NO_WRITE_ACCESS => $messenger->ephemeral(
                $this->responseUrl,
                'Your GitHub account ' . SlackText::escape((string) $user?->github_login) . " has no write access to {$repository}. Only people with write access can approve or reject.",
            ),
            CoOwnerDecisionException::NOT_REQUESTER => $messenger->ephemeral(
                $this->responseUrl,
                'Only ' . SlackText::escape($this->firstName($requesters->for($request->task))) . ', who asked for this change, can withdraw it.',
            ),
            CoOwnerDecisionException::ACCESS_CHECK_FAILED => $messenger->ephemeral($this->responseUrl, GitHubWriteAccess::ACCESS_CHECK_FAILED_REASON),
            default => $messenger->ephemeral(
                $this->responseUrl,
                'Approval did not go through. GitHub answered ' . ($exception->status ?? 'no response') . ' "' . SlackText::escape($exception->getMessage()) . '" when Yak tried to open the PR from '
                . SlackText::escape((string) $request->task->branch_name) . ". Nothing changed. If you no longer have write access to {$repository}, ask a repository admin. Otherwise press Approve again.",
            ),
        };
    }

    private function showResolved(CoOwnerRequest $request, CoOwnerMessenger $messenger): void
    {
        $messenger->ephemeral($this->responseUrl, 'Only visible to you: this request was already resolved, no changes were made.');
        $messenger->resolved($request->fresh() ?? $request);
    }

    private function firstName(?User $requester): string
    {
        return $requester === null ? 'the person' : explode(' ', trim($requester->name))[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function connectBlocks(CoOwnerRequest $request, string $text): array
    {
        return [
            ['type' => 'section', 'text' => ['type' => 'mrkdwn', 'text' => $text]],
            ['type' => 'actions', 'elements' => [[
                'type' => 'button',
                'action_id' => 'yak_link_connect_github',
                'url' => GitHubAccountController::connectUrl("/tasks/{$request->task_id}"),
                'text' => ['type' => 'plain_text', 'text' => 'Connect GitHub'],
            ]]],
        ];
    }
}
