<?php

namespace App\Services\Threads;

use App\DataTransferObjects\ThreadMessageInput;
use App\DataTransferObjects\ThreadMessageResult;
use App\Enums\MessageDelivery;
use App\Enums\SteeringMode;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadOwnerSide;
use App\Exceptions\ThreadActionRefused;
use App\Models\Thread;
use App\Models\User;
use App\Services\Teleport\ThreadNotOwnedByYak;
use App\YakPromptBuilder;

/**
 * Sends a person's message into a thread: starts a turn, steers the running
 * one or queues it. Web and app both come through here.
 */
class ThreadMessageSender
{
    private const DEVICE_SENTENCE = 'Your computer is working on this thread. Hand it back to Yak before sending a message.';

    private const NOT_STARTED_SENTENCE = 'This thread has not started yet. It starts when its repository is set up.';

    public function __construct(private readonly ThreadTurns $turns, private readonly SendKeys $sendKeys) {}

    /**
     * @throws ThreadActionRefused
     */
    public function send(Thread $thread, User $user, ThreadMessageInput $input): ThreadMessageResult
    {
        if ($input->sendKey === null) {
            return $this->sendOnce($thread, $user, $input);
        }

        // The stored result is looked up before any check: after /clear the old thread has no tasks, and a repeat must still get the first answer.
        $stored = $this->sendKeys->once(
            "thread-send-result:{$thread->id}:{$user->id}:{$input->sendKey}",
            fn (): array => $this->sendOnce($thread, $user, $input)->toArray(),
        );

        return ThreadMessageResult::fromArray($stored);
    }

    private function sendOnce(Thread $thread, User $user, ThreadMessageInput $input): ThreadMessageResult
    {
        if ($thread->owner_side === ThreadOwnerSide::Device) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_OWNED_BY_DEVICE, self::DEVICE_SENTENCE);
        }

        if (! $thread->isOpen()) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, 'This thread is closed.');
        }

        if (in_array($thread->blocked_reason, [ThreadBlockReason::WaitingForRepository, ThreadBlockReason::RepositorySetupFailed], true)) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_BLOCKED, self::NOT_STARTED_SENTENCE, ['reason' => $thread->blocked_reason->value]);
        }

        // Recording and then opening the PR publishes a private conversation, so it needs the same acknowledgement as Open PR.
        if ($input->intent === 'record_walkthrough_and_open_pr') {
            ThreadPublishGuard::assertAcknowledged($thread, request()->boolean(ThreadPublishGuard::ACKNOWLEDGEMENT_KEY));
        }

        return $this->deliver($thread, $user, $input);
    }

    private function deliver(Thread $thread, User $user, ThreadMessageInput $input): ThreadMessageResult
    {
        $task = $thread->headTask() ?? throw ThreadActionRefused::because(ThreadActionRefused::THREAD_BLOCKED, self::NOT_STARTED_SENTENCE, ['reason' => 'no_task']);
        $isRunning = ThreadTurns::activeTurn($thread) !== null;

        try {
            if (ClearCommand::matches($input->text)) {
                if ($isRunning) {
                    throw ThreadActionRefused::because(ThreadActionRefused::TURN_RUNNING, 'Stop the running turn before clearing.');
                }

                return new ThreadMessageResult('sent', null, ClearCommand::apply($thread, $task)->public_id);
            }

            if ($input->intent !== null && $isRunning) {
                throw ThreadActionRefused::because(ThreadActionRefused::TURN_RUNNING, 'Claude is already working on this thread.');
            }

            if (! $isRunning) {
                $prompt = match ($input->intent) {
                    'add_preview_manifest' => YakPromptBuilder::previewSetupPrompt('missing'),
                    'fix_preview' => YakPromptBuilder::previewSetupPrompt('failing'),
                    'record_walkthrough', 'record_walkthrough_and_open_pr' => YakPromptBuilder::recordWalkthroughPrompt(),
                    default => $input->text,
                };
                $run = $this->turns->start(
                    $task,
                    $prompt,
                    $user,
                    $input->model,
                    shownText: $input->text,
                    intent: $input->intent,
                    storeAttachments: $input->storeAttachments,
                    sendKey: $input->sendKey,
                );

                return new ThreadMessageResult('sent', rescue(fn () => $run->prompt_uuid, null, false), null);
            }

            $message = $this->turns->steer(
                $thread,
                $input->text,
                $user,
                $input->model,
                $input->storeAttachments,
                'thread',
                $input->sendKey,
                $input->delivery === MessageDelivery::AfterTurn,
            );

            return new ThreadMessageResult($message->mode === SteeringMode::Steer ? 'sent' : 'queued', $message->uuid, null);
        } catch (ThreadBusy) {
            throw ThreadActionRefused::because(ThreadActionRefused::TURN_RUNNING, 'This thread is already starting a turn. Give it a moment.');
        } catch (ThreadNotOwnedByYak) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_OWNED_BY_DEVICE, self::DEVICE_SENTENCE);
        }
    }
}
