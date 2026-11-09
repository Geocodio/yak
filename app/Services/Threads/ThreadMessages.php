<?php

namespace App\Services\Threads;

use App\Enums\CommandState;
use App\Models\PendingSteeringMessage;
use App\Models\TaskRun;
use App\Models\Thread;
use App\Models\YakTask;
use Illuminate\Support\Collection;

/**
 * Every message a person sent into a thread, with whether the model has taken it in and where in
 * the conversation that happened. State follows the runner's command lifecycle.
 *
 * Each message is {id, uuid, text, authorName, model, state, readAfterSequence, sentAt, readAt}.
 */
class ThreadMessages
{
    /**
     * @return Collection<int, array<string, mixed>>
     */
    public static function for(Thread $thread): Collection
    {
        $runs = TaskRun::query()->where('thread_id', $thread->id)->whereNotNull('prompt')->with('driver')->get();
        $taskIds = $thread->tasks()->pluck('id');

        /** @var list<array<string, mixed>> $messages */
        $messages = [];

        foreach ($runs as $run) {
            $state = $run->prompt_uuid === null ? null : $run->command_states?->get($run->prompt_uuid);

            $messages[] = [
                'id' => 'run-' . $run->id,
                'uuid' => $run->prompt_uuid,
                'text' => (string) $run->prompt,
                'authorName' => $run->driver?->name,
                'model' => $run->model,
                'state' => match (true) {
                    $state === CommandState::Cancelled => 'cancelled',
                    in_array($state, [CommandState::Started, CommandState::Completed], true) => 'read',
                    default => 'sent',
                },
                'readAfterSequence' => $run->prompt_read_after_sequence,
                'sentAt' => $run->created_at?->toIso8601String(),
                'readAt' => $run->agent_started_at?->toIso8601String(),
            ];
        }

        $steers = PendingSteeringMessage::query()->withoutGlobalScopes()->whereIn('root_task_id', $taskIds)->get();

        foreach ($steers as $steer) {
            $messages[] = [
                'id' => 'steer-' . $steer->id,
                'uuid' => $steer->uuid,
                'text' => $steer->text,
                'authorName' => $steer->author_name,
                'model' => $steer->model,
                'state' => $steer->absorbed_at === null ? 'sent' : 'read',
                'readAfterSequence' => $steer->read_after_sequence,
                'sentAt' => $steer->created_at?->toIso8601String(),
                'readAt' => $steer->absorbed_at?->toIso8601String(),
            ];
        }

        $unstarted = YakTask::query()->whereIn('id', $taskIds)->whereNotIn('id', $runs->pluck('yak_task_id'))->get();

        foreach ($unstarted as $task) {
            $messages[] = [
                'id' => 'task-' . $task->id,
                'uuid' => null,
                'text' => (string) $task->description,
                'authorName' => $task->author_name,
                'model' => $task->requested_model,
                'state' => 'sent',
                'readAfterSequence' => null,
                'sentAt' => $task->created_at?->toIso8601String(),
                'readAt' => null,
            ];
        }

        usort($messages, fn (array $a, array $b): int => $a['sentAt'] <=> $b['sentAt']);

        return collect($messages);
    }
}
