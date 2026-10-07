<?php

namespace App\Http\Controllers\Tasks;

use App\Enums\TaskMode;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\SendTaskMessageRequest;
use App\Models\PendingSteeringMessage;
use App\Models\TaskAttachment;
use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;
use App\Services\RepoClarificationResolver;
use App\Services\TaskLogger;
use App\Services\ThreadBuilder;
use App\Support\AttachmentNumbering;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;

class TaskMessageController extends Controller
{
    public function store(SendTaskMessageRequest $request, YakTask $task): RedirectResponse
    {
        $text = trim((string) $request->validated('message'));
        $conversation = $task->conversation();
        $head = $conversation->last() ?? $task;

        // Labels number across the whole conversation; re-label any that an
        // earlier message (or someone replying at the same time) already used.
        $claimed = AttachmentNumbering::claim((array) $request->input('attachment_refs', []), $text, AttachmentNumbering::nextNumberFor($conversation));
        $text = $claimed['text'];
        $request->merge(['attachment_refs' => $claimed['references']]);

        /** @var TaskStatus $status */
        $status = $head->status;

        $state = match (true) {
            $status === TaskStatus::AwaitingClarification && $head->pendingClarificationQuestions() !== [] => 'questions',
            $status === TaskStatus::AwaitingClarification => 'clarification',
            in_array($status, [TaskStatus::Running, TaskStatus::AwaitingCi, TaskStatus::Retrying, TaskStatus::Pending], true) => 'steering',
            $head->acceptsFollowUp() => 'follow_up',
            default => null,
        };

        [$flashKey, $message] = match ($state) {
            'questions' => ['error', 'Answer the questions in the form above.'],
            'clarification' => ['success', $this->sendClarification($request, $head, $text)],
            'steering' => ['success', $this->sendSteering($request, $head, $text)],
            'follow_up' => $this->sendFollowUpMessage($request, $head, $text),
            default => ['error', 'This conversation is closed.'],
        };

        return redirect()->route('tasks.show', $task)->with($flashKey, $message);
    }

    /**
     * Free-text replies only answer "which repository?" now (structured
     * questions go through the answers form). Choosing a repo restarts the
     * run from scratch, which reads the task's request attachments, so the
     * files join those.
     */
    private function sendClarification(SendTaskMessageRequest $request, YakTask $head, string $text): string
    {
        $attachments = TaskAttachment::storeFromRequest($request, ['yak_task_id' => $head->id]);
        self::logClarificationReply($head, $text, $attachments);

        RepoClarificationResolver::resolve($head, $text);

        return 'Reply sent. Yak is continuing the task.';
    }

    /**
     * The log entry doubles as the thread's record of the reply: ThreadBuilder
     * shows each one as a message with the files sent alongside it.
     *
     * @param  Collection<int, TaskAttachment>  $attachments
     */
    private static function logClarificationReply(YakTask $head, string $text, Collection $attachments): void
    {
        TaskLogger::info($head, ThreadBuilder::CLARIFICATION_REPLY_LOG, [
            'reply' => $text,
            'author' => auth()->user()?->name,
            'attachment_ids' => $attachments->pluck('id')->all(),
        ]);
    }

    private function sendSteering(SendTaskMessageRequest $request, YakTask $head, string $text): string
    {
        $message = PendingSteeringMessage::queueFor($head, $text, 'dashboard');
        TaskAttachment::storeFromRequest($request, ['pending_steering_message_id' => $message->id]);

        TaskLogger::info($head, 'Steering message queued via Yak UI');

        return 'Queued -- Yak will pick this up when the current run finishes.';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function sendFollowUpMessage(SendTaskMessageRequest $request, YakTask $head, string $text): array
    {
        $isResearch = $head->mode === TaskMode::Research;
        $closedMessage = $isResearch ? 'This conversation is closed.' : 'This PR is no longer open for changes.';

        if (! $head->acceptsFollowUp()) {
            return ['error', $closedMessage];
        }

        $attachments = TaskAttachment::storeFromRequest($request);
        $child = app(FollowUpTaskFactory::class)->create($head, $text, 'dashboard', authorName: auth()->user()?->name, attachments: $attachments);

        if ($child === null) {
            $attachments->each->delete();

            return ['error', $closedMessage];
        }

        return ['success', $isResearch ? 'Sent to Yak. It will answer in this conversation.' : 'Sent to Yak. It will push changes to this PR.'];
    }
}
