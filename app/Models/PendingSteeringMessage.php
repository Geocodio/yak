<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingSteeringMessage extends Model
{
    protected $fillable = ['root_task_id', 'text', 'source', 'reviewer_login'];

    /**
     * `$reviewerLogin` is set for messages that came from a GitHub review,
     * so the follow-up that eventually flushes them can re-request review.
     */
    public static function queueFor(YakTask $task, string $text, string $source, ?string $reviewerLogin = null): self
    {
        $root = $task->conversation()->first() ?? $task;

        return self::create([
            'root_task_id' => $root->id,
            'text' => $text,
            'source' => $source,
            'reviewer_login' => $reviewerLogin,
        ]);
    }
}
