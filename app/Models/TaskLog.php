<?php

namespace App\Models;

use Database\Factories\TaskLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TaskLog extends Model
{
    /** @use HasFactory<TaskLogFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = [
        'level' => 'info',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'json',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<YakTask, $this>
     */
    public function task(): BelongsTo
    {
        return $this->belongsTo(YakTask::class, 'yak_task_id');
    }
}
