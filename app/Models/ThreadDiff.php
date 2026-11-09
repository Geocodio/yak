<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The latest diff of a thread's working tree against the merge base with the default branch.
 *
 * @property int $thread_id
 * @property int|null $task_run_id
 * @property string $base_sha
 * @property string $head_sha
 * @property int $files_count
 * @property int $additions
 * @property int $deletions
 * @property bool $is_truncated
 * @property CarbonImmutable $captured_at
 */
class ThreadDiff extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['is_truncated' => 'boolean', 'captured_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Thread, $this>
     */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Thread::class);
    }

    /**
     * @return HasMany<ThreadDiffFile, $this>
     */
    public function files(): HasMany
    {
        return $this->hasMany(ThreadDiffFile::class)->orderBy('path');
    }
}
