<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FollowUpPendingComment extends Model
{
    protected $guarded = [];

    /**
     * @return BelongsTo<YakTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(YakTask::class, 'yak_task_id');
    }
}
