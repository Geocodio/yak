<?php

namespace App\Services\Threads;

use App\DataTransferObjects\ThreadMessageInput;
use App\DataTransferObjects\ThreadMessageResult;
use App\Enums\MessageDelivery;
use App\Enums\SteeringMode;
use App\Enums\TaskMode;
use App\Enums\ThreadBlockReason;
use App\Enums\ThreadOwnerSide;
use App\Enums\TurnMessageKind;
use App\Exceptions\ThreadActionRefused;
use App\Models\PendingSteeringMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\ThreadTurnMessage;
use App\Models\User;
use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;
use App\Services\Teleport\ThreadNotOwnedByYak;
use App\YakPromptBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends a person's message into a thread: starts a turn, steers the running
 * one or queues it. Web and app both come through here.
 */
class ThreadMessageSender
{
    private const DEVICE_SENTENCE = 'Your computer is working on this thread. Hand it back to Yak before sending a message.';

    private const CANNOT_SEND_SENTENCE = 'You cannot send messages to this thread.';

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

        if ($thread->is_legacy && ! $thread->isOpen()) {
            return $this->continueLegacy($thread, $user, $input);
        }

        if (! $thread->isOpen()) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, 'This thread is closed.');
        }

        if (! $thread->hasRepository()) {
            throw ThreadActionRefused::because(ThreadActionRefused::NO_REPOSITORY, ThreadActionRefused::NO_REPOSITORY_SENTENCE);
        }

        // A thread that has run before keeps its conversation: the message is queued and runs once the repository is ready.
        $isRepositoryBlocked = in_array($thread->blocked_reason, [ThreadBlockReason::WaitingForRepository, ThreadBlockReason::RepositorySetupFailed], true);

        if ($isRepositoryBlocked && ! $thread->runs()->exists()) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_BLOCKED, self::NOT_STARTED_SENTENCE, ['reason' => $thread->blocked_reason->value]);
        }

        // Recording and then opening the PR publishes a private conversation, so it needs the same acknowledgement as Open PR.
        if ($input->intent === 'record_walkthrough_and_open_pr') {
            ThreadPublishGuard::assertAcknowledged($thread, $input->acknowledgedPrivatePublish);
        }

        return $this->deliver($thread, $user, $input);
    }

    /**
     * A message to work from before threads continues it the way a follow-up
     * on the old task did, in a new thread that continues from this one.
     */
    private function continueLegacy(Thread $thread, User $user, ThreadMessageInput $input): ThreadMessageResult
    {
        $access = app(ThreadAccess::class);
        $continuing = Thread::query()->withoutVisibilityScope()->where('continued_from_thread_id', $thread->id)->latest('id')->first();

        if ($continuing !== null && ! $access->canDrive($user, $continuing)) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, self::CANNOT_SEND_SENTENCE);
        }

        if ($continuing?->isOpen() === true) {
            return new ThreadMessageResult($this->sendOnce($continuing, $user, $input)->status, null, $continuing->public_id);
        }

        $root = YakTask::query()->withoutGlobalScopes()->find($thread->legacy_root_task_id)
            ?? throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, 'This thread is closed.');
        $head = $root->conversation()->last() ?? $root;
        $headThread = $head->thread_id === null || $head->thread_id === $thread->id
            ? null
            : Thread::query()->withoutVisibilityScope()->find($head->thread_id);

        if ($headThread !== null && ! $access->canDrive($user, $headThread)) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, self::CANNOT_SEND_SENTENCE);
        }

        $closedMessage = $head->mode === TaskMode::Research ? 'This conversation is closed.' : 'This PR is no longer open for changes.';
        $stored = $input->storeAttachments === null ? null : ($input->storeAttachments)(new PendingSteeringMessage);
        $attachments = $stored instanceof Collection ? $stored : collect();
        $child = app(FollowUpTaskFactory::class)->create($head, $input->text, 'dashboard', authorName: $user->name, attachments: $attachments);

        if ($child === null) {
            $attachments->each->delete();

            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_CLOSED, $closedMessage);
        }

        // The worker's `forTask` opens the thread when this one cannot, so a failure here never loses the sent follow-up.
        try {
            $threadId = app(ThreadFactory::class)->forTask($child)->public_id;
        } catch (Throwable $exception) {
            report($exception);
            $threadId = $thread->public_id;
        }

        return new ThreadMessageResult('sent', null, $threadId);
    }

    private function deliver(Thread $thread, User $user, ThreadMessageInput $input): ThreadMessageResult
    {
        $task = $thread->headTask() ?? throw ThreadActionRefused::because(ThreadActionRefused::THREAD_BLOCKED, self::NOT_STARTED_SENTENCE, ['reason' => 'no_task']);
        $isRunning = ThreadTurns::activeTurn($thread) !== null;

        if ($input->sendKey !== null && ($replayed = $this->alreadyRecorded($thread, $user, $input->sendKey)) !== null) {
            return $replayed;
        }

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
                    answersQuestion: $input->intent === null,
                    effort: $input->effort,
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
                $input->effort,
            );

            return new ThreadMessageResult($message->mode === SteeringMode::Steer ? 'sent' : 'queued', $message->uuid, null);
        } catch (ThreadBusy) {
            throw ThreadActionRefused::because(ThreadActionRefused::TURN_RUNNING, 'This thread is already starting a turn. Give it a moment.');
        } catch (ThreadNotOwnedByYak) {
            throw ThreadActionRefused::because(ThreadActionRefused::THREAD_OWNED_BY_DEVICE, self::DEVICE_SENTENCE);
        }
    }

    /**
     * The result of a message this person's send key already recorded, so a repeat after the key's cache entry or lock is gone does not act twice.
     */
    private function alreadyRecorded(Thread $thread, User $user, string $sendKey): ?ThreadMessageResult
    {
        $queued = PendingSteeringMessage::query()
            ->where('root_task_id', $thread->messagesRootTask()->id)
            ->where('send_key', $sendKey)
            ->where('author_user_id', $user->id)
            ->first();

        if ($queued !== null) {
            return new ThreadMessageResult($queued->mode === SteeringMode::Steer ? 'sent' : 'queued', $queued->uuid, null);
        }

        $drained = DB::table('drained_send_keys')
            ->where('root_task_id', $thread->messagesRootTask()->id)
            ->where('send_key', $sendKey)
            ->where('author_user_id', $user->id)
            ->first();

        if ($drained !== null) {
            return new ThreadMessageResult('sent', $drained->message_uuid, null);
        }

        $prompt = ThreadTurnMessage::query()
            ->where('send_key', $sendKey)
            ->where('kind', TurnMessageKind::Prompt)
            ->whereIn('task_run_id', TaskRun::query()->where('thread_id', $thread->id)->where('driver_user_id', $user->id)->select('id'))
            ->first();

        return $prompt === null ? null : new ThreadMessageResult('sent', $prompt->uuid, null);
    }
}
