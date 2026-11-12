<?php

namespace App\Models;

use App\Enums\RestoreStepName;
use App\Enums\RestoreStepStatus;
use Carbon\CarbonImmutable;
use Database\Factories\ThreadRestoreStepFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Progress record of one step of a sandbox restore.
 *
 * @property int $id
 * @property int $thread_id
 * @property int $position
 * @property RestoreStepName $step
 * @property RestoreStepStatus $status
 * @property string|null $output
 * @property string|null $error
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 */
class ThreadRestoreStep extends Model
{
    /** @use HasFactory<ThreadRestoreStepFactory> */
    use HasFactory;

    protected $guarded = [];

    /** @var array<string, mixed> */
    protected $attributes = ['status' => 'pending'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'step' => RestoreStepName::class,
            'status' => RestoreStepStatus::class,
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }
}
