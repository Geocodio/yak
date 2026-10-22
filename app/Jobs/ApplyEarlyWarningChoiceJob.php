<?php

namespace App\Jobs;

use App\Channels\Slack\CoOwnerBlocks;
use App\Channels\Slack\CoOwnerMessenger;
use App\Channels\Slack\SlackText;
use App\Channels\Slack\UserNameResolver;
use App\Models\User;
use App\Models\YakTask;
use App\Services\CoOwner\CoOwnerTagging;
use App\Services\CoOwner\CoOwnerTaggingException;
use App\Services\CoOwner\GitHubWriteAccess;
use App\Services\CoOwner\SlackUserResolver;
use App\Services\CoOwner\TaskRequester;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Applies a co-owner pick or the decline button of the early warning. Refusals
 * go back through the click's `response_url` as an ephemeral message; success
 * rewrites the warning message in place. `$action` is CoOwnerBlocks::TAG_ACTION_PREFIX
 * or CoOwnerBlocks::DECLINE_ACTION_PREFIX.
 */
class ApplyEarlyWarningChoiceJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public readonly int $taskId,
        public readonly string $slackUserId,
        public readonly string $action,
        public readonly ?string $selectedSlackUserId,
        public readonly string $responseUrl,
        public readonly ?string $channel,
        public readonly ?string $messageTs,
    ) {
        $this->onQueue('default');
    }

    public function handle(
        SlackUserResolver $slackUsers,
        CoOwnerTagging $tagging,
        CoOwnerMessenger $messenger,
        TaskRequester $requesters,
    ): void {
        $task = YakTask::find($this->taskId);

        if ($task === null) {
            return;
        }

        $root = $task->rootTask();
        $actor = $slackUsers->userFor($this->slackUserId) ?? $this->slackUserId;
        $coOwner = null;

        try {
            if ($this->action === CoOwnerBlocks::DECLINE_ACTION_PREFIX) {
                $tagging->decline($root, $actor);
            } else {
                if ($this->selectedSlackUserId === null || $this->selectedSlackUserId === '') {
                    return;
                }

                $coOwner = $slackUsers->userFor($this->selectedSlackUserId);

                if ($coOwner === null) {
                    throw CoOwnerTaggingException::notLinked();
                }

                $tagging->tag($root, $coOwner, $actor);
            }
        } catch (CoOwnerTaggingException $exception) {
            $this->refuse($exception, $root, $coOwner, $messenger, $requesters);

            return;
        }

        $messenger->updateEarlyWarning($root, $this->channel, $this->messageTs);
    }

    private function refuse(CoOwnerTaggingException $exception, YakTask $root, ?User $coOwner, CoOwnerMessenger $messenger, TaskRequester $requesters): void
    {
        $text = match ($exception->reason) {
            CoOwnerTaggingException::NOT_REQUESTER => $this->notRequesterText($requesters->for($root)),
            CoOwnerTaggingException::NOT_LINKED => SlackText::escape($this->pickedName($coOwner))
                . ' has not linked GitHub in Yak, so Yak cannot check their access. Pick someone else, or ask them to connect GitHub on their profile.',
            CoOwnerTaggingException::NO_WRITE_ACCESS => SlackText::escape($this->pickedName($coOwner))
                . ' has no write access to ' . SlackText::escape((string) $root->repository?->github_full_name)
                . '. Only people with write access can co-own a change.',
            CoOwnerTaggingException::ACCESS_CHECK_FAILED => GitHubWriteAccess::ACCESS_CHECK_FAILED_REASON,
            default => 'This request already moved on, so the pick was not saved.',
        };

        $messenger->ephemeral($this->responseUrl, $text);
    }

    private function pickedName(?User $coOwner): string
    {
        return $coOwner === null
            ? (UserNameResolver::resolve($this->selectedSlackUserId) ?? 'That person')
            : $coOwner->name;
    }

    private function notRequesterText(?User $requester): string
    {
        $firstName = $requester === null ? '' : explode(' ', trim($requester->name))[0];

        return $firstName === ''
            ? 'Only the person who asked for this change can pick the co-owner for this request.'
            : 'Only ' . SlackText::escape($firstName) . ' can pick the co-owner for this request.';
    }
}
