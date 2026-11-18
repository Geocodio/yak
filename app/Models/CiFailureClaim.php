<?php

namespace App\Models;

use Database\Factories\CiFailureClaimFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Records that Yak has taken on, or deliberately skipped, a CI job that fails
 * across branches, so the same cause is not claimed twice.
 *
 * @property int $id
 * @property string $repo
 * @property string $workflow_path
 * @property string|null $job_name
 * @property string|null $test_class
 * @property int|null $yak_task_id
 * @property string|null $skipped_pr_url
 * @property string|null $fix_merge_sha
 * @property string|null $last_outcome
 * @property int $attempt_count
 * @property Carbon|null $blocked_until
 * @property Carbon|null $created_at
 */
class CiFailureClaim extends Model
{
    /** @use HasFactory<CiFailureClaimFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'attempt_count' => 'integer',
            'blocked_until' => 'datetime',
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
