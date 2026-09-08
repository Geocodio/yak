<?php

namespace App\Models;

use App\Enums\TaskStatus;
use Database\Factories\FlakyTestClaimFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Records that Yak has already dealt with a failing test class, either by
 * opening a task for it or by finding a pull request that already fixes it.
 *
 * A claim suppresses a new task only while it is *live* (see liveClassesFor).
 * The row is never unique per test: a test Yak failed to fix, or whose fix
 * was closed unmerged, must be claimable again.
 *
 * @property Carbon $created_at
 * @property string $repo
 * @property string $test_class
 * @property string|null $skipped_pr_url
 * @property int|null $yak_task_id
 */
class FlakyTestClaim extends Model
{
    /** @use HasFactory<FlakyTestClaimFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
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
     * The test classes in this repository that a new task must not be created
     * for, keyed by class name so the caller can report why it skipped.
     *
     * A claim is live when the task that made it is still running, when that
     * task's PR is open, or when that task's PR merged recently enough that
     * the scan window still contains pre-fix failures. A skip claim (no task,
     * just the PR Yak deferred to) is live for the same window, after which
     * the pull request check runs again and defers again if the PR is still
     * there.
     *
     * @return array<string, FlakyTestClaim>
     */
    public static function liveClassesFor(string $repo): array
    {
        $window = now()->subHours(self::windowHours());

        $claims = [];

        $rows = static::query()
            ->where('repo', $repo)
            ->where(function (Builder $query) use ($window): void {
                $query
                    ->where(function (Builder $skip) use ($window): void {
                        $skip->whereNotNull('skipped_pr_url')
                            ->where('created_at', '>=', $window);
                    })
                    ->orWhereHas('task', function (Builder $task) use ($window): void {
                        $task
                            ->whereIn('status', TaskStatus::activeValues())
                            ->orWhere(function (Builder $open): void {
                                $open->whereNotNull('pr_url')
                                    ->whereNull('pr_merged_at')
                                    ->whereNull('pr_closed_at');
                            })
                            ->orWhere('pr_merged_at', '>=', $window);
                    });
            })
            // Ascending, so the newest live claim is the one that survives
            // the keyed assignment below and gets reported.
            ->orderBy('id')
            ->get();

        foreach ($rows as $claim) {
            $claims[$claim->test_class] = $claim;
        }

        return $claims;
    }

    public static function windowHours(): int
    {
        return (int) config('yak.ci_scan.max_failure_age_hours', 48);
    }
}
