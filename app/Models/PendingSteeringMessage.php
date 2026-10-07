<?php

namespace App\Models;

use App\Facades\Telemetry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
}
