<?php

namespace App\Http\Controllers\Tasks;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\SendTaskMessageRequest;
use App\Jobs\ClarificationReplyJob;
use App\Models\PendingSteeringMessage;
use App\Models\YakTask;
use App\Services\FollowUpTaskFactory;
use App\Services\RepoClarificationResolver;
use App\Services\TaskLogger;
use Illuminate\Http\RedirectResponse;

class TaskMessageController extends Controller
{
    public function store(SendTaskMessageRequest $request, YakTask $task): RedirectResponse
    {
        $text = trim((string) $request->validated('message'));
        $head = $task->conversation()->last() ?? $task;

        /** @var TaskStatus $status */
        $status = $head->status;

        $state = match (true) {
            $status === TaskStatus::AwaitingClarification => 'clarification',
            in_array($status, [TaskStatus::Running, TaskStatus::AwaitingCi, TaskStatus::Retrying, TaskStatus::Pending], true) => 'steering',
            $head->prIsOpen() => 'follow_up',
            default => null,
        };

        [$flashKey, $message] = match ($state) {
            'clarification' => ['success', $this->sendClarification($head, $text)],
            'steering' => ['success', $this->sendSteering($head, $text)],
            'follow_up' => $this->sendFollowUpMessage($head, $text),
            default => ['error', 'This conversation is closed.'],
        };

        return redirect()->route('tasks.show', $task)->with($flashKey, $message);
    }

    private function sendClarification(YakTask $head, string $text): string
    {
        TaskLogger::info($head, 'Clarification reply submitted via Yak UI');

        if (RepoClarificationResolver::awaitingRepoChoice($head)) {
            RepoClarificationResolver::resolve($head, $text);
        } else {
            ClarificationReplyJob::dispatch($head, $text);
        }

        return 'Reply sent. Yak is continuing the task.';
    }

    private function sendSteering(YakTask $head, string $text): string
    {
        PendingSteeringMessage::queueFor($head, $text, 'dashboard');

        TaskLogger::info($head, 'Steering message queued via Yak UI');

        return 'Queued -- Yak will pick this up when the current run finishes.';
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function sendFollowUpMessage(YakTask $head, string $text): array
    {
        if (! $head->prIsOpen()) {
            return ['error', 'This PR is no longer open for changes.'];
        }

        $child = app(FollowUpTaskFactory::class)->create($head, $text, 'dashboard', authorName: auth()->user()?->name);

        if ($child === null) {
            return ['error', 'This PR is no longer open for changes.'];
        }

        return ['success', 'Sent to Yak. It will push changes to this PR.'];
    }
}
