<?php

namespace App\Models;

use App\Enums\SteeringMode;
use App\Facades\Telemetry;
use App\Services\TaskLogger;
use App\Services\ThreadBuilder;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @property SteeringMode $mode
 * @property string|null $send_key
 */
class PendingSteeringMessage extends Model
{
    protected $fillable = ['root_task_id', 'text', 'source', 'mode', 'reviewer_login', 'author_name', 'author_user_id', 'uuid', 'model', 'delivered_at', 'absorbed_at', 'send_key'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => SteeringMode::class,
            'delivered_at' => 'datetime',
            'absorbed_at' => 'datetime',
        ];
    }

    /**
     * Files attached to the message; they move to the follow-up run when
     * the queue flushes.
     *
     * @return HasMany<TaskAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(TaskAttachment::class)->orderBy('id');
    }

    /**
     * `$reviewerLogin` is set for messages that came from a GitHub review,
     * so the follow-up that eventually flushes them can re-request review.
     */
    public static function queueFor(
        YakTask $task,
        string $text,
        string $source,
        ?string $reviewerLogin = null,
        SteeringMode $mode = SteeringMode::Queue,
        ?string $authorName = null,
        ?int $authorUserId = null,
    ): self {
        $root = $task->rootTask();

        Telemetry::feature('steering', ['status' => $task->status->value, 'mode' => $mode->value], task: $task, source: $source);

        return self::create([
            'root_task_id' => $root->id,
            'text' => $text,
            'source' => $source,
            'mode' => $mode,
            'reviewer_login' => $reviewerLogin,
            'author_name' => $authorName,
            'author_user_id' => $authorUserId,
        ]);
    }

    /**
     * Messages still waiting on the task's conversation, oldest first.
     *
     * @return Collection<int, self>
     */
    public static function waitingFor(YakTask $task): Collection
    {
        return self::where('root_task_id', $task->rootTask()->id)->with('attachments')->orderBy('id')->get();
    }

    /**
     * Messages steered at the task's conversation that the running agent
     * has not been given yet, with their attachments, oldest first.
     *
     * @return Collection<int, self>
     */
    public static function steeredFor(YakTask $task): Collection
    {
        return self::where('root_task_id', $task->rootTask()->id)
            ->where('mode', SteeringMode::Steer)
            ->with('attachments')
            ->orderBy('id')
            ->get();
    }

    /**
     * Hand the given steered messages to the running agent through `$send`,
     * oldest first, holding their rows locked so they cannot be withdrawn or
     * switched mid-way. Only once `$send` reports success is a message
     * recorded in the thread as the person's message, its files moved onto
     * `$task`, and its row deleted. The first failure stops the rest, which
     * keep waiting. A message removed or switched back to queued since it
     * was read is skipped. Returns how many were handed over.
     *
     * @param  Collection<int, self>  $messages
     * @param  Closure(self): bool  $send
     */
    public static function deliverSteeredFor(YakTask $task, Collection $messages, Closure $send): int
    {
        if ($messages->isEmpty()) {
            return 0;
        }

        return DB::transaction(function () use ($task, $messages, $send): int {
            $locked = self::whereIn('id', $messages->pluck('id'))
                ->where('mode', SteeringMode::Steer)
                ->lockForUpdate()
                ->with('attachments')
                ->orderBy('id')
                ->get();

            $delivered = 0;

            foreach ($locked as $message) {
                if (! $send($message)) {
                    break;
                }

                TaskAttachment::where('pending_steering_message_id', $message->id)->update([
                    'yak_task_id' => $task->id,
                    'pending_steering_message_id' => null,
                    'context' => TaskAttachment::CONTEXT_STEERING,
                ]);

                $message->delete();

                TaskLogger::info($task, ThreadBuilder::STEERING_MESSAGE_LOG, [
                    'reply' => $message->text,
                    'author' => $message->author_name,
                    'source' => $message->source,
                    'attachment_ids' => $message->attachments->pluck('id')->all(),
                ]);

                $delivered++;
            }

            return $delivered;
        });
    }

    /**
     * Whether the message can be handed to the running agent. A GitHub
     * review has to wait for the follow-up, which re-requests the review.
     */
    public function canSteer(): bool
    {
        return $this->reviewer_login === null;
    }

    /**
     * Only the signed-in person who wrote a message can change its text; channel messages and older rows have no author.
     */
    public function canEditTextBy(?User $user): bool
    {
        return $user !== null && $this->author_user_id !== null && $this->reviewer_login === null && $this->author_user_id === $user->id;
    }

    /**
     * Withdraw the message before Yak sees it, deleting its files too.
     */
    public function withdraw(): void
    {
        $this->attachments->each(fn (TaskAttachment $attachment) => $attachment->delete());
        $this->delete();
    }

    /**
     * Take every undelivered reply queued on the task's conversation and render it as
     * markdown, deleting the rows. Their files move onto `$task`, whose run
     * now carries the replies, so the agent still gets them. Returns null
     * when nothing is queued. A message already handed to a turn (a
     * `ThreadTurnMessage` references it) stays as history and is not drained.
     */
    public static function drainFor(YakTask $task): ?string
    {
        $messages = self::where('root_task_id', $task->rootTask()->id)
            ->whereNotIn('id', ThreadTurnMessage::query()->whereNotNull('pending_steering_message_id')->select('pending_steering_message_id'))
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            return null;
        }

        TaskAttachment::whereIn('pending_steering_message_id', $messages->pluck('id'))->update([
            'yak_task_id' => $task->id,
            'pending_steering_message_id' => null,
            'context' => TaskAttachment::CONTEXT_REQUEST,
        ]);

        self::whereIn('id', $messages->pluck('id'))->delete();

        return self::render($messages);
    }

    /**
     * A GitHub review's formatted text (quoted summary, "Inline comments:",
     * fenced hunks) breaks a bullet list item, so it gets its own paragraph
     * instead. Everything else keeps the bullet.
     *
     * @param  Collection<int, PendingSteeringMessage>  $messages
     */
    public static function render(Collection $messages): string
    {
        return $messages
            ->map(fn (PendingSteeringMessage $m): string => $m->source === 'github_review'
                ? "\n" . $m->text . "\n"
                : '- ' . $m->text)
            ->implode("\n");
    }
}
