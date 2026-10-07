<?php

namespace App\Models;

use App\Facades\Telemetry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class PendingSteeringMessage extends Model
{
    protected $fillable = ['root_task_id', 'text', 'source', 'reviewer_login'];

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
    public static function queueFor(YakTask $task, string $text, string $source, ?string $reviewerLogin = null): self
    {
        $root = $task->conversation()->first() ?? $task;

        Telemetry::feature('steering', ['status' => $task->status->value], task: $task, source: $source);

        return self::create([
            'root_task_id' => $root->id,
            'text' => $text,
            'source' => $source,
            'reviewer_login' => $reviewerLogin,
        ]);
    }

    /**
     * Take every reply queued on the task's conversation and render it as
     * markdown, deleting the rows. Their files move onto `$task`, whose run
     * now carries the replies, so the agent still gets them. Returns null
     * when nothing is queued.
     */
    public static function drainFor(YakTask $task): ?string
    {
        $root = $task->conversation()->first() ?? $task;
        $messages = self::where('root_task_id', $root->id)->orderBy('id')->get();

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
