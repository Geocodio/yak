<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Database\Factories\CiFailureClaimFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

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

    /**
     * Claims that still suppress a new task for the repository: the claiming
     * task is active or has an open PR, the claim is blocked, or it is a
     * recent skip or recent fix. A claim with a fix merge sha is only live
     * for failures whose head lacks that sha, which the caller checks.
     *
     * @return Collection<int, CiFailureClaim>
     */
    public static function liveFor(string $repo): Collection
    {
        $window = now()->subHours((int) config('yak.ci_failure.claim_window_hours'));

        return static::query()
            ->with('task')
            ->where('repo', $repo)
            ->where(function (Builder $query) use ($window): void {
                $query
                    ->where('blocked_until', '>', now())
                    ->orWhere(fn (Builder $skip) => $skip->whereNotNull('skipped_pr_url')->where('created_at', '>=', $window))
                    ->orWhere(fn (Builder $fix) => $fix->whereNotNull('fix_merge_sha')->where('created_at', '>=', $window))
                    ->orWhereHas('task', function (Builder $task): void {
                        $task
                            ->whereIn('status', TaskStatus::activeValues())
                            ->orWhere(fn (Builder $open) => $open->whereNotNull('pr_url')->whereNull('pr_merged_at')->whereNull('pr_closed_at'));
                    });
            })
            ->orderBy('id')
            ->get();
    }
}
