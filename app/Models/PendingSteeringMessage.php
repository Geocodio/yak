<?php

namespace App\Models;

use App\Enums\SteeringMode;
use App\Facades\Telemetry;
use App\Services\TaskLogger;
use App\Services\ThreadBuilder;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * @property SteeringMode $mode
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $delivered_at
 */
class PendingSteeringMessage extends Model
{
    public const STEERING_PREAMBLE = "The person you are working for sent this message while you were working. Take it into account from here on:\n\n";

    /** Seconds a delivered message may wait before the dashboard says it is queued behind a running step. */
    private const STEP_WAIT_SECONDS = 3;

    protected $fillable = ['root_task_id', 'text', 'source', 'mode', 'reviewer_login', 'author_name', 'model'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mode' => SteeringMode::class,
            'sent_at' => 'datetime',
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
        ?string $model = null,
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
            'model' => $model,
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
            ->whereNull('sent_at')
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

                $message->absorb($task);

                $delivered++;
            }

            return $delivered;
        });
    }

    /**
     * The message reached the model: record it in the thread as the person's
     * message, move its files onto `$task` and delete the row.
     */
    public function absorb(YakTask $task): void
    {
        $attachmentIds = $this->attachments()->pluck('id')->all();

        TaskAttachment::where('pending_steering_message_id', $this->id)->update([
            'yak_task_id' => $task->id,
            'pending_steering_message_id' => null,
            'context' => TaskAttachment::CONTEXT_STEERING,
        ]);

        $this->delete();

        TaskLogger::info($task, ThreadBuilder::STEERING_MESSAGE_LOG, [
            'reply' => $this->text,
            'author' => $this->author_name,
            'source' => $this->source,
            'attachment_ids' => $attachmentIds,
        ]);
    }

    /**
     * Where a steer stands for the dashboard. A message handed to the runner
     * that the model has not taken in yet is waiting for the running step.
     *
     * @return 'waiting'|'sent'|'delivered'|'queued_until_step_finishes'
     */
    public function steeringState(): string
    {
        // ponytail: the runner reports no tool-in-flight flag, so a delivered
        // message still unabsorbed after a few seconds is read as a running step.
        return match (true) {
            $this->sent_at === null => 'waiting',
            $this->delivered_at === null => 'sent',
            $this->delivered_at->diffInSeconds(now(), true) > self::STEP_WAIT_SECONDS => 'queued_until_step_finishes',
            default => 'delivered',
        };
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
     * Withdraw the message before Yak sees it, deleting its files too.
     */
    public function withdraw(): void
    {
        $this->attachments->each(fn (TaskAttachment $attachment) => $attachment->delete());
        $this->delete();
    }

    /**
     * Take every reply queued on the task's conversation and render it as
     * markdown, deleting the rows. Their files move onto `$task`, whose run
     * now carries the replies, so the agent still gets them. Returns null
     * when nothing is queued.
     */
    public static function drainFor(YakTask $task): ?string
    {
        $messages = self::where('root_task_id', $task->rootTask()->id)->orderBy('id')->get();

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
