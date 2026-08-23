<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PendingSteeringMessage extends Model
{
    protected $fillable = ['root_task_id', 'text', 'source'];

    public static function queueFor(YakTask $task, string $text, string $source): self
    {
        $root = $task->conversation()->first() ?? $task;

        return self::create(['root_task_id' => $root->id, 'text' => $text, 'source' => $source]);
    }
}
