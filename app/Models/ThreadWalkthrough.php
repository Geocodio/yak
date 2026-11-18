<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ThreadWalkthroughFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One numbered recording in a thread. The newest is read from the task's live
 * artifacts; an archived one keeps its own copies of the video and screenshots.
 *
 * @property int $id
 * @property int $thread_id
 * @property int $yak_task_id
 * @property int $number
 * @property int|null $anchor_sequence
 * @property float|null $duration_seconds
 * @property list<array{title: string, startSeconds: float}>|null $chapters
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable|null $created_at
 */
class ThreadWalkthrough extends Model
{
    /** @use HasFactory<ThreadWalkthroughFactory> */
    use HasFactory;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'chapters' => 'array',
            'duration_seconds' => 'float',
            'archived_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /**
     * @return HasMany<Artifact, $this>
     */
    public function artifacts(): HasMany
    {
        return $this->hasMany(Artifact::class);
    }
}
